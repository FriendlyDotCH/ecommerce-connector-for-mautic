<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\LeadSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\ShoppingActivity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

class LeadSubscriberTest extends TestCase
{
    private EcommerceOrderRepository&MockObject $orderRepository;

    private Translator&MockObject $translator;

    private RouterInterface&MockObject $router;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EcommerceOrderRepository::class);
        $this->translator      = $this->createMock(Translator::class);
        $this->router          = $this->createMock(RouterInterface::class);
    }

    public function testTimelineEventOpensOrderModal(): void
    {
        $this->translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if ('mautic.plugin.ecommerce.timeline.order' === $id) {
                return 'Order placed';
            }

            if ('mautic.plugin.ecommerce.tab.order_details' === $id) {
                return 'Order '.$params['%order%'];
            }

            return $id;
        });

        $this->router->expects($this->once())
            ->method('generate')
            ->with('mautic_ecommerce_order_action', [
                'leadId'   => 9,
                'objectId' => 42,
            ])
            ->willReturn('/s/ecommerce/order/9/42');

        $this->orderRepository->method('getOrdersForLeadTimeline')->willReturn([
            'results' => [
                [
                    'id'             => 42,
                    'order_id'       => 'LN-100',
                    'order_total'    => '24.9900',
                    'order_currency' => 'USD',
                    'order_source'   => 'WooCommerce',
                    'order_date'     => '2026-08-12 22:06:00',
                    'date_added'     => '2026-08-12 22:06:00',
                    'email_id'       => null,
                    'email_name'     => null,
                ],
            ],
            'total' => 1,
        ]);

        $lead = new Lead();
        $lead->setId(9);

        $event = new LeadTimelineEvent($lead);

        $subscriber = new LeadSubscriber($this->orderRepository, $this->translator, $this->router, $this->createMock(ShoppingActivity::class), new CurrencyFormatter(), $this->createMock(Config::class));
        $subscriber->onTimelineGenerate($event);

        $events = $event->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('USD 24.99 (WooCommerce)', $events[0]['eventLabel']);
        $this->assertSame('@EcommerceConnector/SubscribedEvents/Timeline/order.html.twig', $events[0]['contentTemplate']);
        $this->assertSame('/s/ecommerce/order/9/42', $events[0]['extra']['modalUrl']);
        $this->assertSame('Order LN-100', $events[0]['extra']['modalHeader']);
    }
}
