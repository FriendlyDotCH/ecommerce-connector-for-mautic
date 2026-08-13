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

    protected function setUp(): void
    {
        $this->orderRepository     = $this->createMock(EcommerceOrderRepository::class);
        $this->orderItemRepository = $this->createMock(EcommerceOrderItemRepository::class);
        $this->config              = $this->createMock(Config::class);
        $this->router              = $this->createMock(RouterInterface::class);
        $this->currencyFormatter   = new CurrencyFormatter();
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
                    'merchant'       => 'acme',
                    'location'       => 'hixson',
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
                ],
            ],
        ]);

        $subscriber = new ContactProfileTabSubscriber(
            $this->orderRepository,
            $this->orderItemRepository,
            $this->config,
            $this->currencyFormatter,
            $this->router
        );

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
        $this->assertSame('acme', $contentEvent->getTemplates()[0]['vars']['orders'][0]['merchant']);
        $this->assertCount(1, $contentEvent->getTemplates()[0]['vars']['orders'][0]['items']);
    }

    public function testUnpublishedIntegrationDoesNotInjectTabs(): void
    {
        $lead = new Lead();
        $lead->setId(7);

        $this->config->method('isPublished')->willReturn(false);

        $subscriber = new ContactProfileTabSubscriber(
            $this->orderRepository,
            $this->orderItemRepository,
            $this->config,
            $this->currencyFormatter,
            $this->router
        );
        $event = new CustomContentEvent('@MauticLead/Lead/lead.html.twig', 'tabs', ['lead' => $lead]);
        $subscriber->onInjectCustomContent($event);

        $this->assertSame([], $event->getTemplates());
    }
}
