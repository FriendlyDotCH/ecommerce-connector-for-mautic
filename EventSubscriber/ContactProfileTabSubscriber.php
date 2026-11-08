<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LocationFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\ShoppingActivity;
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
        private EcommerceBehaviourRepository $behaviourRepository,
        private ShoppingActivity $shoppingActivity,
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
            // Lifetime value, last order total and behaviours are in base currency.
            $defaultCurrency = $this->config->getBaseCurrency();

            $event->addTemplate('@EcommerceConnector/Lead/Tab/ecommerce-tab-content.html.twig', [
                'lead'                   => $lead,
                'stats'                  => $stats,
                'orders'                 => $this->formatOrders($orders['results']),
                'defaultCurrency'        => $defaultCurrency,
                'lifetimeValueFormatted' => $this->currencyFormatter->format($defaultCurrency, $stats['lifetime_value']),
                'lastOrderTotalFormatted'=> $stats['last_order_date']
                    ? $this->currencyFormatter->format($defaultCurrency, $stats['last_order_total'])
                    : null,
                'behaviours'             => $this->formatBehaviours($lead, $defaultCurrency),
                'activity'               => $this->formatActivity($leadId),
                'activityRetentionDays'  => $this->config->getEventRetentionDays(),
                'timing'                 => [
                    'daysSinceLastOrder'   => $lead->getFieldValue(LeadCommerceFieldDefinition::DAYS_SINCE_LAST_ORDER),
                    'avgDaysBetweenOrders' => $lead->getFieldValue(LeadCommerceFieldDefinition::AVG_DAYS_BETWEEN_ORDERS),
                    'nextOrderDate'        => $lead->getFieldValue(LeadCommerceFieldDefinition::NEXT_ORDER_DATE),
                    'lifecycleStage'       => $lead->getFieldValue(LeadCommerceFieldDefinition::LIFECYCLE_STAGE),
                    'rfmScore'             => $lead->getFieldValue(LeadCommerceFieldDefinition::RFM_SCORE),
                ],
            ]);
        }
    }

    /**
     * Behaviour fields sit in the ecommerce field group, which the profile hides, so show them here.
     *
     * @return list<array{behaviour: \MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour, value: string}>
     */
    private function formatBehaviours(Lead $lead, string $currency): array
    {
        $formatted = [];

        foreach ($this->behaviourRepository->findPublished() as $behaviour) {
            $value = (float) $lead->getFieldValue((string) $behaviour->getFieldAlias());

            $formatted[] = [
                'behaviour' => $behaviour,
                'value'     => PurchaseCriteria::METRIC_SPEND === $behaviour->getMetric()
                    ? $this->currencyFormatter->format($currency, $value)
                    : number_format($value, 0, '.', ' '),
            ];
        }

        return $formatted;
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
                    'unitPrice' => null !== $item['unit_price'] ? $this->currencyFormatter->format($currency, $item['unit_price']) : null,
                    'refundedQty' => $item['refunded_qty'] ?? 0,
                ];
            }

            $status   = (string) ($order['status'] ?? 'paid');
            $refunded = (float) ($order['refunded_total'] ?? 0);

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
                'status'              => $status,
                'refundedFormatted'   => $refunded > 0 ? $this->currencyFormatter->format($currency, $refunded) : null,
                'netTotalFormatted'   => $this->currencyFormatter->format($currency, (float) ($order['net_total'] ?? $order['order_total'])),
                'dateAdded'           => $order['order_date'],
                'emailId'             => !empty($order['email_id']) ? (int) $order['email_id'] : null,
                'emailName'           => !empty($order['email_name']) ? (string) $order['email_name'] : null,
                'emailLink'           => $emailLink,
                'items'               => $items,
            ];
        }

        return $formatted;
    }

    /** Rows shown under Shopping activity; older entries stay in the timeline and the API. */
    private const ACTIVITY_ROWS = 20;

    /**
     * @return list<array{kind: string, at: \DateTimeImmutable, products: list<string>, value: ?string, status: ?string}>
     */
    private function formatActivity(int $leadId): array
    {
        $rows = [];
        foreach (array_slice($this->shoppingActivity->forLead($leadId), 0, self::ACTIVITY_ROWS) as $entry) {
            $event    = $entry['event'];
            $currency = $event->getCurrency() ?: $this->config->getDefaultCurrency();

            if (ShoppingActivity::KIND_VIEW === $entry['kind']) {
                $products = array_filter([(string) $event->getProductName()]);
                $amount   = $event->getPrice();
            } else {
                $products = [];
                foreach ($event->getCartItems() ?? [] as $item) {
                    $name       = (string) ($item['item_name'] ?? $item['name'] ?? $item['item_id'] ?? '');
                    $products[] = isset($item['qty']) ? $item['qty'].' × '.$name : $name;
                }
                $amount = $event->getCartValue();
            }

            $rows[] = [
                'kind'     => $entry['kind'],
                'at'       => $entry['at'],
                'products' => array_values($products),
                'value'    => null !== $amount && '' !== $amount ? $this->currencyFormatter->format($currency, $amount) : null,
                'status'   => $entry['status'],
            ];
        }

        return $rows;
    }
}
