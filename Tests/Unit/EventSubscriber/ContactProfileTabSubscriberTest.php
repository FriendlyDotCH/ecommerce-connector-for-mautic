<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ContactProfileTabSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\LocationFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

class ContactProfileTabSubscriberTest extends TestCase
{
    private EcommerceOrderRepository&MockObject $orderRepository;

    private EcommerceOrderItemRepository&MockObject $orderItemRepository;

    private Config&MockObject $config;

    private RouterInterface&MockObject $router;

    private CurrencyFormatter $currencyFormatter;

    private LocationFormatter $locationFormatter;

    protected function setUp(): void
    {
        $this->orderRepository     = $this->createMock(EcommerceOrderRepository::class);
        $this->orderItemRepository = $this->createMock(EcommerceOrderItemRepository::class);
        $this->config              = $this->createMock(Config::class);
        $this->router              = $this->createMock(RouterInterface::class);
        $this->currencyFormatter   = new CurrencyFormatter();
        $this->locationFormatter   = new LocationFormatter();
    }

    private function createSubscriber(): ContactProfileTabSubscriber
    {
        return new ContactProfileTabSubscriber(
            $this->orderRepository,
            $this->orderItemRepository,
            $this->config,
            $this->currencyFormatter,
            $this->locationFormatter,
            $this->router
        );
    }

    public function testTabTemplatesAreInjectedForContactProfile(): void
    {
        $lead = new Lead();
        $lead->setId(7);

        $this->config->method('isPublished')->willReturn(true);
        $this->config->method('getDefaultCurrency')->willReturn('USD');
        $this->orderRepository->method('getCommerceStatsForLead')->willReturn([
            'order_count'      => 2,
            'lifetime_value'   => '250.00',
            'last_order_date'  => '2026-06-28 12:00:00',
            'last_order_total' => '150.00',
        ]);
        $this->orderRepository->method('getOrdersForLeadTimeline')->willReturn([
            'results' => [
                [
                    'id'             => 1,
                    'order_id'       => 'order-1',
                    'order_total'    => '150.0000',
                    'order_currency' => 'USD',
                    'order_source'   => 'woocommerce',
                    'location'       => 'hixson',
                    'order_date'     => '2026-06-28 12:00:00',
                    'date_added'     => '2026-06-28 12:00:00',
                    'email_id'       => null,
                    'email_name'     => null,
                ],
            ],
            'total' => 1,
        ]);
        $this->orderItemRepository->method('getItemsGroupedByOrderIds')->willReturn([
            1 => [
                [
                    'item_id'   => '1722',
                    'item_name' => 'Smart Water 1 Liter',
                    'qty'       => 1,
                    'category'  => 'Beverages',
                    'unit_price' => '2.50',
                ],
            ],
        ]);

        $subscriber = $this->createSubscriber();

        $tabEvent = new CustomContentEvent('@MauticLead/Lead/lead.html.twig', 'tabs', ['lead' => $lead]);
        $subscriber->onInjectCustomContent($tabEvent);

        $this->assertSame(
            '@EcommerceConnector/Lead/Tab/ecommerce-tab.html.twig',
            $tabEvent->getTemplates()[0]['template']
        );
        $this->assertSame(2, $tabEvent->getTemplates()[0]['vars']['orderCount']);

        $contentEvent = new CustomContentEvent('@MauticLead/Lead/lead.html.twig', 'tabs.content', ['lead' => $lead]);
        $subscriber->onInjectCustomContent($contentEvent);

        $this->assertSame(
            '@EcommerceConnector/Lead/Tab/ecommerce-tab-content.html.twig',
            $contentEvent->getTemplates()[0]['template']
        );
        $this->assertSame($lead, $contentEvent->getTemplates()[0]['vars']['lead']);
        $this->assertCount(1, $contentEvent->getTemplates()[0]['vars']['orders']);
        $this->assertSame('Hixson', $contentEvent->getTemplates()[0]['vars']['orders'][0]['location']);
        $this->assertCount(1, $contentEvent->getTemplates()[0]['vars']['orders'][0]['items']);
        $this->assertSame($this->currencyFormatter->format('USD', '2.50'), $contentEvent->getTemplates()[0]['vars']['orders'][0]['items'][0]['unitPrice']);
    }

    public function testLocationSlugIsFormattedToSentenceCase(): void
    {
        $lead = new Lead();
        $lead->setId(8);

        $this->config->method('isPublished')->willReturn(true);
        $this->config->method('getDefaultCurrency')->willReturn('USD');
        $this->orderRepository->method('getCommerceStatsForLead')->willReturn([
            'order_count'      => 1,
            'lifetime_value'   => '10.00',
            'last_order_date'  => '2026-06-28 12:00:00',
            'last_order_total' => '10.00',
        ]);
        $this->orderRepository->method('getOrdersForLeadTimeline')->willReturn([
            'results' => [
                [
                    'id'             => 2,
                    'order_id'       => 'order-2',
                    'order_total'    => '10.0000',
                    'order_currency' => 'USD',
                    'order_source'   => 'WooCommerce',
                    'location'       => 'the-dragon',
                    'order_date'     => '2026-06-28 12:00:00',
                    'date_added'     => '2026-06-28 12:00:00',
                    'email_id'       => null,
                    'email_name'     => null,
                ],
            ],
            'total' => 1,
        ]);
        $this->orderItemRepository->method('getItemsGroupedByOrderIds')->willReturn([]);

        $subscriber = $this->createSubscriber();

        $contentEvent = new CustomContentEvent('@MauticLead/Lead/lead.html.twig', 'tabs.content', ['lead' => $lead]);
        $subscriber->onInjectCustomContent($contentEvent);

        $this->assertSame('The dragon', $contentEvent->getTemplates()[0]['vars']['orders'][0]['location']);
    }

    public function testUnpublishedIntegrationDoesNotInjectTabs(): void
    {
        $lead = new Lead();
        $lead->setId(7);

        $this->config->method('isPublished')->willReturn(false);

        $subscriber = $this->createSubscriber();
        $event = new CustomContentEvent('@MauticLead/Lead/lead.html.twig', 'tabs', ['lead' => $lead]);
        $subscriber->onInjectCustomContent($event);

        $this->assertSame([], $event->getTemplates());
    }
}
