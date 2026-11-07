<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Campaign;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\Tag;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CampaignSubscriber;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\EmailTokenSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\AbandonmentDetector;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class AbandonmentFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['enable_storefront_events' => 1, 'cart_abandon_minutes' => 60, 'browse_abandon_minutes' => 120]);
    }

    public function testAbandonmentRules(): void
    {
        $abandoner = $this->createContact('abandoner@example.com');
        $buyer     = $this->createContact('buyer@example.com');
        $checker   = $this->createContact('checker@example.com');
        $browser   = $this->createContact('browser@example.com');
        $recent    = $this->createContact('recent@example.com');

        $this->createTagCampaign(CampaignSubscriber::DECISION_ABANDONED_CART, ['min_value' => 20], 'cart-abandoned', [$abandoner, $buyer, $recent]);
        $this->createTagCampaign(CampaignSubscriber::DECISION_ABANDONED_CHECKOUT, [], 'checkout-abandoned', [$checker]);
        $this->createTagCampaign(CampaignSubscriber::DECISION_ABANDONED_BROWSE, ['categories' => 'Diffusers'], 'browse-abandoned', [$browser]);

        // Cart 90 minutes ago, nothing since: abandoned.
        $this->sendEvent('abandoner@example.com', 'added_to_cart', '-90 minutes');
        // Cart 90 minutes ago, then an order: not abandoned.
        $this->sendEvent('buyer@example.com', 'added_to_cart', '-90 minutes');
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload('ab-1', '25', 'web', 'EUR', null, null, null, [], new \DateTimeImmutable('-80 minutes')),
            $this->em->getRepository(Lead::class)->find($buyer->getId())
        );
        // Cart then checkout: only the checkout counts.
        $this->sendEvent('checker@example.com', 'added_to_cart', '-120 minutes');
        $this->sendEvent('checker@example.com', 'started_checkout', '-100 minutes');
        // Viewed a diffuser 3 hours ago: browse abandoned.
        $this->sendEvent('browser@example.com', 'viewed_product', '-3 hours');
        // Cart 10 minutes ago: too early.
        $this->sendEvent('recent@example.com', 'added_to_cart', '-10 minutes');

        $result = static::getContainer()->get(AbandonmentDetector::class)->run();
        Assert::assertSame(['started_checkout' => 1, 'added_to_cart' => 1, 'viewed_product' => 1], $result);

        Assert::assertSame(['cart-abandoned'], $this->tagsOf($abandoner));
        Assert::assertSame([], $this->tagsOf($buyer));
        Assert::assertSame(['checkout-abandoned'], $this->tagsOf($checker));
        Assert::assertSame(['browse-abandoned'], $this->tagsOf($browser));
        Assert::assertSame([], $this->tagsOf($recent));

        // Each event fires once.
        Assert::assertSame(['started_checkout' => 0, 'added_to_cart' => 0, 'viewed_product' => 0], static::getContainer()->get(AbandonmentDetector::class)->run());

        // The abandonment email can show the cart.
        $send = new EmailSendEvent(null, [
            'content' => '<p>{ecommerce_cart}</p><a href="{ecommerce_cart_url}">Back to your cart</a>',
            'lead'    => ['id' => $abandoner->getId()],
        ]);
        static::getContainer()->get(EmailTokenSubscriber::class)->onEmailGenerate($send);
        $tokens = $send->getTokens();
        Assert::assertStringContainsString('Lavender Candle', $tokens['{ecommerce_cart}']);
        Assert::assertStringContainsString('2 &times;', $tokens['{ecommerce_cart}']);
        Assert::assertSame('https://shop.example/cart?restore=1', $tokens['{ecommerce_cart_url}']);
    }

    private function sendEvent(string $email, string $type, string $when): void
    {
        $event = [
            'event'       => $type,
            'email'       => $email,
            'occurred_at' => (new \DateTimeImmutable($when))->format(\DATE_ATOM),
            'product'     => ['id' => 'DIF-1', 'name' => 'Reed Diffuser', 'category' => 'Diffusers', 'price' => '30'],
            'cart'        => [
                'id'    => 'cart-1',
                'url'   => 'https://shop.example/cart?restore=1',
                'items' => [['item_id' => 'CND-1', 'name' => 'Lavender Candle', 'qty' => 2, 'price' => '12.50', 'category' => 'Candles']],
            ],
        ];
        if ('viewed_product' === $type) {
            unset($event['cart']);
        }

        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/event', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
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
        $decision->setProperties(array_merge(['min_value' => null, 'categories' => '', 'products' => ''], $decisionConfig));
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
