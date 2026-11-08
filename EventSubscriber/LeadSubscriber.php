<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\ShoppingActivity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class LeadSubscriber implements EventSubscriberInterface
{
    /** Timeline types for storefront activity (product views stay on the eCommerce tab). */
    public const TIMELINE_CART      = 'ecommerce.cart';
    public const TIMELINE_CHECKOUT  = 'ecommerce.checkout';
    public const TIMELINE_ABANDONED = 'ecommerce.abandoned';

    private const TIMELINE_TYPE_BY_KIND = [
        ShoppingActivity::KIND_CART               => self::TIMELINE_CART,
        ShoppingActivity::KIND_CHECKOUT           => self::TIMELINE_CHECKOUT,
        ShoppingActivity::KIND_ABANDONED_CART     => self::TIMELINE_ABANDONED,
        ShoppingActivity::KIND_ABANDONED_CHECKOUT => self::TIMELINE_ABANDONED,
    ];

    public function __construct(
        private EcommerceOrderRepository $orderRepository,
        private Translator $translator,
        private RouterInterface $router,
        private ShoppingActivity $shoppingActivity,
        private CurrencyFormatter $currencyFormatter,
        private Config $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0],
        ];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $this->addOrders($event);
        $this->addShoppingActivity($event);
    }

    private function addOrders(LeadTimelineEvent $event): void
    {
        $eventTypeName = $this->translator->trans('mautic.plugin.ecommerce.timeline.order');
        $event->addEventType(EcommerceEvents::ORDER_RECORDED, $eventTypeName);

        if (!$event->isApplicable(EcommerceEvents::ORDER_RECORDED)) {
            return;
        }

        $leadId = $event->getLeadId();
        if (!$leadId) {
            return;
        }

        $orders = $this->orderRepository->getOrdersForLeadTimeline($leadId, $event->getQueryOptions());

        if ($event->isEngagementCount()) {
            $event->addToCounter(EcommerceEvents::ORDER_RECORDED, $orders['total']);

            return;
        }

        foreach ($orders['results'] as $order) {
            $orderPk  = (int) $order['id'];
            $currency = strtoupper((string) ($order['order_currency'] ?? ''));
            $amount   = number_format((float) $order['order_total'], 2, '.', '');
            $label    = trim(sprintf('%s %s (%s)', $currency, $amount, $order['order_source']));
            $status   = (string) ($order['status'] ?? 'paid');
            if ('paid' !== $status) {
                $label .= ' - '.$this->translator->trans('mautic.plugin.ecommerce.status.'.$status);
            }
            $orderId  = (string) $order['order_id'];

            $event->addEvent([
                'event'           => EcommerceEvents::ORDER_RECORDED,
                'eventId'         => EcommerceEvents::ORDER_RECORDED.'.'.$orderPk,
                'eventLabel'      => $label,
                'eventType'       => $eventTypeName,
                'timestamp'       => $order['order_date'],
                'icon'            => 'ri-shopping-cart-2-line',
                'contactId'       => $leadId,
                'contentTemplate' => '@EcommerceConnector/SubscribedEvents/Timeline/order.html.twig',
                'extra'           => [
                    'orderId'     => $orderId,
                    'modalUrl'    => $this->router->generate('mautic_ecommerce_order_action', [
                        'leadId'   => $leadId,
                        'objectId' => $orderPk,
                    ]),
                    'modalHeader' => $this->translator->trans('mautic.plugin.ecommerce.tab.order_details', [
                        '%order%' => $orderId,
                    ]),
                ],
            ]);
        }
    }

    private function addShoppingActivity(LeadTimelineEvent $event): void
    {
        $typeNames = [];
        foreach (array_unique(self::TIMELINE_TYPE_BY_KIND) as $type) {
            // ecommerce.cart => mautic.plugin.ecommerce.timeline.cart, and so on
            $typeNames[$type] = $this->translator->trans('mautic.plugin.ecommerce.timeline.'.substr($type, strlen('ecommerce.')));
            $event->addEventType($type, $typeNames[$type]);
        }

        $applicable = array_filter(array_keys($typeNames), static fn (string $type): bool => $event->isApplicable($type));
        $leadId     = $event->getLeadId();
        if ([] === $applicable || !$leadId) {
            return;
        }

        $byType = array_fill_keys($applicable, []);
        foreach ($this->shoppingActivity->timelineForLead((int) $leadId) as $entry) {
            $type = self::TIMELINE_TYPE_BY_KIND[$entry['kind']];
            if (isset($byType[$type])) {
                $byType[$type][] = $entry;
            }
        }

        $options = $event->getQueryOptions();
        foreach ($byType as $type => $entries) {
            if ($event->isEngagementCount()) {
                $event->addToCounter($type, count($entries));
                continue;
            }

            if (!empty($options['limit'])) {
                $entries = array_slice($entries, (int) ($options['offset'] ?? 0), (int) $options['limit']);
            }

            foreach ($entries as $entry) {
                $event->addEvent($this->timelineEntry($entry, $type, $typeNames[$type], (int) $leadId));
            }
        }
    }

    /**
     * @param array{kind: string, event: \MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent, at: \DateTimeImmutable, status: ?string} $entry
     *
     * @return array<string, mixed>
     */
    private function timelineEntry(array $entry, string $type, string $typeName, int $leadId): array
    {
        $storefrontEvent = $entry['event'];
        $currency        = $storefrontEvent->getCurrency() ?: $this->config->getDefaultCurrency();
        $items           = $storefrontEvent->getCartItems() ?? [];
        $value           = null !== $storefrontEvent->getCartValue()
            ? $this->currencyFormatter->format($currency, $storefrontEvent->getCartValue())
            : null;

        $label = $this->translator->trans('mautic.plugin.ecommerce.timeline.'.$entry['kind']);
        $parts = array_filter([
            $value,
            [] !== $items ? $this->translator->trans('mautic.plugin.ecommerce.tab.item_count', ['%count%' => count($items)]) : null,
        ]);
        if ([] !== $parts) {
            $label .= ': '.implode(' · ', $parts);
        }
        if (ShoppingActivity::STATUS_ORDERED === $entry['status']) {
            $label .= ' ('.$this->translator->trans('mautic.plugin.ecommerce.activity.status.ordered').')';
        }

        $itemLines = [];
        foreach ($items as $item) {
            $itemLines[] = [
                'qty'   => $item['qty'] ?? null,
                'name'  => (string) ($item['item_name'] ?? $item['name'] ?? $item['item_id'] ?? ''),
                'price' => isset($item['unit_price']) && '' !== (string) $item['unit_price']
                    ? $this->currencyFormatter->format($currency, (string) $item['unit_price'])
                    : null,
            ];
        }

        return [
            'event'           => $type,
            'eventId'         => $entry['kind'].'.'.$storefrontEvent->getId(),
            'eventLabel'      => $label,
            'eventType'       => $typeName,
            'timestamp'       => $entry['at']->format('Y-m-d H:i:s'),
            'icon'            => ShoppingActivity::KIND_CART === $entry['kind'] || ShoppingActivity::KIND_CHECKOUT === $entry['kind']
                ? 'ri-shopping-basket-2-line'
                : 'ri-shopping-basket-line',
            'contactId'       => $leadId,
            'contentTemplate' => '@EcommerceConnector/SubscribedEvents/Timeline/cart.html.twig',
            'extra'           => [
                'items'   => $itemLines,
                // Only web links become clickable (the URL comes from the shop's payload).
                'cartUrl' => preg_match('#^https?://#i', (string) $storefrontEvent->getCartUrl()) ? $storefrontEvent->getCartUrl() : null,
            ],
        ];
    }
}
