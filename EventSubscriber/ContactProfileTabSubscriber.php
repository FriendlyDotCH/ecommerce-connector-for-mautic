<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\LocationFormatter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

final class ContactProfileTabSubscriber implements EventSubscriberInterface
{
    private const VIEW = '@MauticLead/Lead/lead.html.twig';

    public function __construct(
        private EcommerceOrderRepository $orderRepository,
        private EcommerceOrderItemRepository $orderItemRepository,
        private Config $config,
        private CurrencyFormatter $currencyFormatter,
        private LocationFormatter $locationFormatter,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onInjectCustomContent', 0],
        ];
    }

    public function onInjectCustomContent(CustomContentEvent $event): void
    {
        if (!$this->config->isPublished()) {
            return;
        }

        $lead = $event->getVars()['lead'] ?? null;
        if (!$lead instanceof Lead || !$lead->getId()) {
            return;
        }

        $leadId     = (int) $lead->getId();
        $orderCount = $this->orderRepository->getCommerceStatsForLead($leadId)['order_count'];

        if ($event->checkContext(self::VIEW, 'tabs')) {
            $event->addTemplate('@EcommerceConnector/Lead/Tab/ecommerce-tab.html.twig', [
                'orderCount' => $orderCount,
            ]);

            return;
        }

        if ($event->checkContext(self::VIEW, 'tabs.content')) {
            $stats           = $this->orderRepository->getCommerceStatsForLead($leadId);
            $orders          = $this->orderRepository->getOrdersForLeadTimeline($leadId);
            $defaultCurrency = $this->config->getDefaultCurrency();

            $event->addTemplate('@EcommerceConnector/Lead/Tab/ecommerce-tab-content.html.twig', [
                'lead'                   => $lead,
                'stats'                  => $stats,
                'orders'                 => $this->formatOrders($orders['results']),
                'defaultCurrency'        => $defaultCurrency,
                'lifetimeValueFormatted' => $this->currencyFormatter->format($defaultCurrency, $stats['lifetime_value']),
                'lastOrderTotalFormatted'=> $stats['last_order_date']
                    ? $this->currencyFormatter->format($defaultCurrency, $stats['last_order_total'])
                    : null,
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatOrders(array $orders): array
    {
        $orderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);
        $itemsByOrderId = $this->orderItemRepository->getItemsGroupedByOrderIds($orderIds);
        $formatted      = [];

        foreach ($orders as $order) {
            $currency = strtoupper((string) ($order['order_currency'] ?? $this->config->getDefaultCurrency()));
            $amount   = number_format((float) $order['order_total'], 2, '.', '');
            $orderPk  = (int) $order['id'];

            $emailLink = null;
            if (!empty($order['email_id'])) {
                $emailLink = $this->router->generate('mautic_email_action', [
                    'objectAction' => 'view',
                    'objectId'     => (int) $order['email_id'],
                ]);
            }

            $items = [];
            foreach ($itemsByOrderId[$orderPk] ?? [] as $item) {
                $items[] = [
                    'itemId'   => $item['item_id'],
                    'itemName' => $item['item_name'],
                    'qty'      => $item['qty'],
                    'category' => $item['category'],
                ];
            }

            $formatted[] = [
                'id'                  => $orderPk,
                'orderId'             => (string) $order['order_id'],
                'orderSource'         => (string) $order['order_source'],
                'location'            => $this->locationFormatter->format(
                    !empty($order['location']) ? (string) $order['location'] : null
                ),
                'orderTotal'          => $amount,
                'orderTotalFormatted' => $this->currencyFormatter->format($currency, $amount),
                'orderCurrency'       => $currency,
                'dateAdded'           => $order['date_added'],
                'emailId'             => !empty($order['email_id']) ? (int) $order['email_id'] : null,
                'emailName'           => !empty($order['email_name']) ? (string) $order['email_name'] : null,
                'emailLink'           => $emailLink,
                'items'               => $items,
            ];
        }

        return $formatted;
    }
}
