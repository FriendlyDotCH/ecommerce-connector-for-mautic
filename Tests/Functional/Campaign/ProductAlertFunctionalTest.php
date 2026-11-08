<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Campaign;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\Tag;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ProductAlertSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductAlertDispatcher;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class ProductAlertFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        // IpLookupHelper caches IP entities in a static array that outlives the test database reset.
        static::getContainer()->get(IpLookupHelper::class)->reset();
        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['enable_storefront_events' => 1]);
    }

    public function testBackInStockAlertsInterestedContactsOnce(): void
    {
        $viewer  = $this->createContact('viewer@example.com');
        $carter  = $this->createContact('carter@example.com');
        $bought  = $this->createContact('bought-since@example.com');
        $old     = $this->createContact('old-view@example.com');
        $other   = $this->createContact('other-product@example.com');
        $outside = $this->createContact('not-in-campaign@example.com');

        $this->createTagCampaign(ProductAlertSubscriber::DECISION_BACK_IN_STOCK, ['days' => 30], 'restocked', [$viewer, $carter, $bought, $old, $other]);

        $this->pushProduct(['stock_status' => 'outofstock', 'stock_quantity' => 0]);

        $this->sendEvent('viewer@example.com', 'viewed_product', 'CND-1', '-2 days');
        $this->sendEvent('carter@example.com', 'added_to_cart', 'CND-1', '-3 days');
        $this->sendEvent('bought-since@example.com', 'viewed_product', 'CND-1', '-5 days');
        $this->sendEvent('old-view@example.com', 'viewed_product', 'CND-1', '-40 days');
        $this->sendEvent('other-product@example.com', 'viewed_product', 'DIF-1', '-1 day');
        $this->sendEvent('not-in-campaign@example.com', 'viewed_product', 'CND-1', '-1 day');

        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload('BIS-1', '25', 'woocommerce', 'EUR', null, null, null, [new OrderItemPayload('CND-1', 'Lavender Candle', 1, 'Candles', '25', 0, '155')], new \DateTimeImmutable('-1 day')),
            $this->em->getRepository(Lead::class)->find($bought->getId())
        );

        // Back in stock.
        $this->pushProduct(['stock_status' => 'instock', 'stock_quantity' => 8]);
        $stats = static::getContainer()->get(ProductAlertDispatcher::class)->run();

        Assert::assertSame(['back_in_stock' => ['changes' => 1, 'alerted' => 2]], $stats);
        Assert::assertSame(['restocked'], $this->tagsOf($viewer));
        Assert::assertSame(['restocked'], $this->tagsOf($carter));
        foreach ([$bought, $old, $other, $outside] as $contact) {
            Assert::assertSame([], $this->tagsOf($contact), (string) $contact->getEmail());
        }

        // Nothing left to process; selling out and restocking again within 7 days alerts nobody again.
        Assert::assertSame([], static::getContainer()->get(ProductAlertDispatcher::class)->run());
        $this->pushProduct(['stock_status' => 'outofstock', 'stock_quantity' => 0]);
        $this->pushProduct(['stock_status' => 'instock', 'stock_quantity' => 3]);
        Assert::assertSame(['back_in_stock' => ['changes' => 1, 'alerted' => 0]], static::getContainer()->get(ProductAlertDispatcher::class)->run());
    }

    public function testPriceDropAndLowStockThresholds(): void
    {
        $buyer  = $this->createContact('buyer@example.com');
        $viewer = $this->createContact('viewer@example.com');

        $this->createTagCampaign(ProductAlertSubscriber::DECISION_PRICE_DROP, ['min_drop_percent' => 20, 'interests' => ['viewed']], 'price-drop', [$viewer]);
        $this->createTagCampaign(ProductAlertSubscriber::DECISION_LOW_STOCK, ['threshold' => 5, 'interests' => ['purchased'], 'days' => 90], 'low-stock', [$buyer]);

        $this->pushProduct(['stock_status' => 'instock', 'stock_quantity' => 20, 'price' => '30']);
        $this->sendEvent('viewer@example.com', 'viewed_product', 'CND-1', '-1 day');
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload('LS-1', '30', 'woocommerce', 'EUR', null, null, null, [new OrderItemPayload('CND-1', 'Lavender Candle', 1, 'Candles', '30', 0, '155')], new \DateTimeImmutable('-30 days')),
            $this->em->getRepository(Lead::class)->find($buyer->getId())
        );

        // 10 % off and stock 20 -> 8: neither threshold met.
        $this->pushProduct(['stock_status' => 'instock', 'stock_quantity' => 8, 'price' => '27']);
        static::getContainer()->get(ProductAlertDispatcher::class)->run();
        Assert::assertSame([], $this->tagsOf($viewer));
        Assert::assertSame([], $this->tagsOf($buyer));

        // 27 -> 20 is 26 % off; stock 8 -> 4 crosses 5.
        $this->pushProduct(['stock_status' => 'instock', 'stock_quantity' => 4, 'price' => '20']);
        static::getContainer()->get(ProductAlertDispatcher::class)->run();
        Assert::assertSame(['price-drop'], $this->tagsOf($viewer));
        Assert::assertSame(['low-stock'], $this->tagsOf($buyer));

        Assert::assertSame(
            ['price_drop', '27.0000', '20.0000'],
            array_values($this->connection->fetchAssociative('SELECT alert_type, old_value, new_value FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ?', [$viewer->getId()]))
        );

        // The alert email shows the product, the old and the new price.
        $send = new EmailSendEvent(null, [
            'content' => '<a href="{ecommerce_alert_product_url}">{ecommerce_alert_product}</a> {ecommerce_alert_product_old_price} {ecommerce_alert_product_price}',
            'lead'    => ['id' => $viewer->getId()],
        ]);
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($send, EmailEvents::EMAIL_ON_DISPLAY);
        $tokens = $send->getTokens();
        Assert::assertSame('Lavender Candle', $tokens['{ecommerce_alert_product}']);
        Assert::assertSame('https://shop.example/candle', $tokens['{ecommerce_alert_product_url}']);
        Assert::assertStringContainsString('27', $tokens['{ecommerce_alert_product_old_price}']);
        Assert::assertStringContainsString('20', $tokens['{ecommerce_alert_product_price}']);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function pushProduct(array $changes): void
    {
        $body = json_encode($changes + ['store' => 'woocommerce', 'id' => 155, 'sku' => 'CND-1', 'name' => 'Lavender Candle', 'price' => '30', 'categories' => ['Candles'], 'url' => 'https://shop.example/candle'], JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/product', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
        Assert::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    private function sendEvent(string $email, string $type, string $productId, string $when): void
    {
        $event = [
            'event'       => $type,
            'email'       => $email,
            'occurred_at' => (new \DateTimeImmutable($when))->format(\DATE_ATOM),
            'product'     => ['id' => '155' === $productId || 'CND-1' === $productId ? '155' : $productId, 'name' => 'Some product', 'price' => '30'],
        ];
        if ('viewed_product' !== $type) {
            $event['cart'] = ['id' => 'cart-'.$email, 'items' => [['item_id' => $productId, 'name' => 'Lavender Candle', 'qty' => 1, 'price' => '30']]];
        }

        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/event', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
        Assert::assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $lead->setDateIdentified(new \DateTime());
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    /**
     * @param array<string, mixed> $decisionConfig
     * @param list<Lead>           $members
     */
    private function createTagCampaign(string $decisionType, array $decisionConfig, string $tagName, array $members): void
    {
        $this->em->persist(new Tag($tagName));

        $campaign = new Campaign();
        $campaign->setName($tagName);
        $campaign->setIsPublished(true);

        $decision = new Event();
        $decision->setOrder(1);
        $decision->setName($decisionType);
        $decision->setType($decisionType);
        $decision->setEventType('decision');
        $decision->setProperties(array_merge(['interests' => ['viewed', 'carted'], 'days' => 30, 'categories' => '', 'products' => ''], $decisionConfig));
        $decision->setCampaign($campaign);

        $addTag = new Event();
        $addTag->setOrder(2);
        $addTag->setName('Add tag');
        $addTag->setType('lead.changetags');
        $addTag->setEventType('action');
        $addTag->setTriggerMode('immediate');
        $addTag->setDecisionPath('yes');
        $addTag->setParent($decision);
        $addTag->setProperties(['add_tags' => [$tagName], 'remove_tags' => []]);
        $addTag->setCampaign($campaign);
        $decision->addChild($addTag);

        $campaign->addEvents([$decision, $addTag]);
        $this->em->persist($campaign);
        $this->em->persist($decision);
        $this->em->persist($addTag);

        foreach ($members as $member) {
            $campaignMember = new CampaignMember();
            $campaignMember->setLead($this->em->getReference(Lead::class, $member->getId()));
            $campaignMember->setCampaign($campaign);
            $campaignMember->setDateAdded(new \DateTime('-1 day'));
            $campaign->addLead(0, $campaignMember);
            $this->em->persist($campaignMember);
        }

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @return list<string>
     */
    private function tagsOf(Lead $contact): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT t.tag FROM '.MAUTIC_TABLE_PREFIX.'lead_tags_xref x JOIN '.MAUTIC_TABLE_PREFIX.'lead_tags t ON t.id = x.tag_id WHERE x.lead_id = ? ORDER BY t.tag',
            [(int) $contact->getId()]
        ));
    }
}
