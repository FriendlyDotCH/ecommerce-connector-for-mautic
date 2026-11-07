<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItem;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CampaignSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\PurchaseHistoryQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CampaignSubscriberTest extends TestCase
{
    private RealTimeExecutioner&MockObject $executioner;

    private ContactTracker&MockObject $contactTracker;

    private CampaignSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->executioner    = $this->createMock(RealTimeExecutioner::class);
        $this->contactTracker = $this->createMock(ContactTracker::class);

        $this->subscriber = new CampaignSubscriber(
            $this->executioner,
            $this->contactTracker,
            $this->createMock(PurchaseHistoryQuery::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testRecentOrderTriggersDecisionForItsContactAndResetsTracker(): void
    {
        $lead = new Lead();
        $lead->setId(42);
        $order = $this->createOrder('49.00', 'web', [['sku-1', 'Candle', 'Candles']], $lead);
        (new \ReflectionProperty(EcommerceOrder::class, 'id'))->setValue($order, 7);

        $this->contactTracker->expects($this->exactly(2))
            ->method('setSystemContact')
            ->willReturnCallback(function (?Lead $contact) use ($lead): void {
                static $call = 0;
                ++$call;
                $this->assertSame(1 === $call ? $lead : null, $contact);
            });
        $this->executioner->expects($this->once())
            ->method('execute')
            ->with(CampaignSubscriber::DECISION_ORDER_PLACED, $order, 'ecommerce', 7);

        $this->subscriber->onOrderRecorded(new EcommerceOrderEvent($order));
    }

    public function testOldOrderDoesNotTriggerCampaigns(): void
    {
        $lead = new Lead();
        $lead->setId(42);
        $order = $this->createOrder('49.00', 'pos', [], $lead);
        $order->setOrderDate(new \DateTime('-2 days'));

        $this->executioner->expects($this->never())->method('execute');

        $this->subscriber->onOrderRecorded(new EcommerceOrderEvent($order));
    }

    public function testOrderWithoutContactDoesNotTriggerCampaigns(): void
    {
        $this->executioner->expects($this->never())->method('execute');

        $this->subscriber->onOrderRecorded(new EcommerceOrderEvent($this->createOrder('10.00', 'web', [])));
    }

    public function testOrderMatchesFilters(): void
    {
        $order = $this->createOrder('49.00', 'Web', [
            ['sku-1', 'Lavender Candle', 'Candles'],
            ['sku-2', 'Diffuser', 'Home'],
        ]);

        $this->assertTrue(CampaignSubscriber::orderMatches($order, []));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['min_total' => '49']));
        $this->assertFalse(CampaignSubscriber::orderMatches($order, ['min_total' => '49.01']));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['sources' => 'pos, web']));
        $this->assertFalse(CampaignSubscriber::orderMatches($order, ['sources' => 'pos']));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['categories' => 'candles']));
        $this->assertFalse(CampaignSubscriber::orderMatches($order, ['categories' => 'Soap']));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['products' => 'SKU-2']));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['products' => 'lavender candle']));
        $this->assertTrue(CampaignSubscriber::orderMatches($order, ['categories' => 'Home', 'products' => 'sku-1']));
        $this->assertFalse(CampaignSubscriber::orderMatches($order, ['categories' => 'Home', 'products' => 'sku-9']));
    }

    public function testCompare(): void
    {
        $this->assertTrue(CampaignSubscriber::compare(100.0, 'gte', 100.0));
        $this->assertFalse(CampaignSubscriber::compare(100.0, 'gt', 100.0));
        $this->assertTrue(CampaignSubscriber::compare(0.0, 'lt', 1.0));
        $this->assertTrue(CampaignSubscriber::compare(0.1 + 0.2, 'eq', 0.3));
        $this->assertTrue(CampaignSubscriber::compare(5.0, 'lte', 5.0));
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $items
     */
    private function createOrder(string $total, string $source, array $items, ?Lead $lead = null): EcommerceOrder
    {
        $order = new EcommerceOrder();
        $order->setOrderId('order-1');
        $order->setOrderTotal($total);
        $order->setOrderSource($source);
        $order->setOrderDate(new \DateTime());
        $order->setDateAdded(new \DateTime());
        $order->setLead($lead);

        foreach ($items as [$itemId, $itemName, $category]) {
            $item = new EcommerceOrderItem();
            $item->setItemId($itemId);
            $item->setItemName($itemName);
            $item->setQty(1);
            $item->setCategory($category);
            $order->addItem($item);
        }

        return $order;
    }
}
