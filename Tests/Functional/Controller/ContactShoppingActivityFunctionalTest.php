<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront events on the contact page: carts, checkouts and abandonments in the history,
 * everything (views too) under Shopping activity on the eCommerce tab.
 */
class ContactShoppingActivityFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testContactPageShowsCondensedShoppingActivity(): void
    {
        $lead = new Lead();
        $lead->setEmail('activity@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        $this->event($lead, EcommerceEvent::TYPE_VIEWED_PRODUCT, '-3 hours', product: 'Rose bath bomb');
        // Two changes to one cart within the delay: one cart, then abandoned.
        $this->event($lead, EcommerceEvent::TYPE_ADDED_TO_CART, '-2 hours', '2390', [['Rose bath bomb', 1]]);
        $this->event($lead, EcommerceEvent::TYPE_ADDED_TO_CART, '-110 minutes', '9260', [['Rose bath bomb', 1], ['Rose gift box', 1]], abandonedAt: '-50 minutes');
        $this->em->flush();
        $this->em->clear();

        $this->configureIntegration();
        $this->client->request(Request::METHOD_GET, '/s/contacts/view/'.$lead->getId());
        Assert::assertTrue($this->client->getResponse()->isOk());
        $crawler = $this->client->getCrawler();

        // eCommerce tab: the view, one cart (latest contents, abandoned) and the abandonment.
        $rows = $crawler->filter('tr.ecommerce-activity-row');
        Assert::assertSame(
            ['ecommerce-activity-abandoned_cart', 'ecommerce-activity-cart', 'ecommerce-activity-view'],
            $rows->each(static fn ($row): string => (string) preg_replace('/.*(ecommerce-activity-\w+).*/', '$1', (string) $row->attr('class')))
        );
        $cartRow = preg_replace('/\s+/u', ' ', $rows->eq(1)->text()) ?? '';
        Assert::assertStringContainsString("1 \u{00D7} Rose gift box", $cartRow);
        Assert::assertStringContainsString('9', $cartRow);
        Assert::assertCount(2, $rows->filter('.label-warning'), 'The cart and its abandonment are marked abandoned.');

        // History: cart and abandonment entries, no product view and no superseded cart change.
        $translator = static::getContainer()->get('translator');
        $history    = $crawler->filter('#timeline-table tr.timeline-row:not(.timeline-details)');
        $byType     = [];
        foreach ($history->each(static fn ($row): array => [trim($row->filter('td.timeline-type')->text('')), trim($row->filter('td.timeline-name')->text(''))]) as [$type, $name]) {
            $byType[$type][] = $name;
        }

        $cartType      = $translator->trans('mautic.plugin.ecommerce.timeline.cart');
        $abandonedType = $translator->trans('mautic.plugin.ecommerce.timeline.abandoned');
        Assert::assertCount(1, $byType[$cartType] ?? [], 'Two changes to one cart are one history entry.');
        Assert::assertStringContainsString('2', $byType[$cartType][0], 'The entry has the latest contents (2 items).');
        Assert::assertCount(1, $byType[$abandonedType] ?? [], 'The abandonment is in the history.');
        Assert::assertStringContainsString($translator->trans('mautic.plugin.ecommerce.timeline.abandoned_cart'), $byType[$abandonedType][0]);
        Assert::assertStringNotContainsString($translator->trans('mautic.plugin.ecommerce.timeline.view'), implode(' ', array_merge(...array_values($byType))), 'Product views stay out of the history.');

        // Cart details: the items and the link back to the shop.
        $details = $crawler->filter('#timeline-table tr.timeline-details');
        Assert::assertStringContainsString('Rose gift box', $details->text(''));
        Assert::assertGreaterThan(0, $details->filter('a[href="https://shop.example/cart?restore=c1"]')->count());
    }

    /**
     * @param list<array{0: string, 1: int}> $items
     */
    private function event(Lead $lead, string $type, string $when, ?string $value = null, array $items = [], ?string $product = null, ?string $abandonedAt = null): void
    {
        $event = new EcommerceEvent();
        $event->setLead($lead);
        $event->setEventType($type);
        $event->setSource('woocommerce');
        $event->setCurrency('HUF');
        $event->setOccurredAt(new \DateTime($when, new \DateTimeZone('UTC')));
        $event->setDateAdded(new \DateTime());
        $event->setProductName($product);
        if (EcommerceEvent::TYPE_VIEWED_PRODUCT !== $type) {
            $event->setCartId('c1');
            $event->setCartValue($value);
            $event->setCartUrl('https://shop.example/cart?restore=c1');
            $event->setCartItems(array_map(static fn (array $item): array => ['item_id' => $item[0], 'item_name' => $item[0], 'qty' => $item[1], 'unit_price' => '1000'], $items));
        }
        if (null !== $abandonedAt) {
            $event->setAbandonedAt(new \DateTime($abandonedAt, new \DateTimeZone('UTC')));
        }
        $this->em->persist($event);
    }
}
