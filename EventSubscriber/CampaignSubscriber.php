<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\Event\StorefrontEventRecordedEvent;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\NoOrderWithinConditionType;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\OrderPlacedDecisionType;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\PurchaseHistoryConditionType;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\StorefrontDecisionType;
use MauticPlugin\EcommerceConnectorBundle\Service\PurchaseHistoryQuery;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventMatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CampaignSubscriber implements EventSubscriberInterface
{
    public const DECISION_ORDER_PLACED = 'ecommerce.order_placed';

    public const CONDITION_PURCHASE_HISTORY = 'ecommerce.purchase_history';

    public const DECISION_VIEWED_PRODUCT    = 'ecommerce.viewed_product';
    public const DECISION_ADDED_TO_CART     = 'ecommerce.added_to_cart';
    public const DECISION_STARTED_CHECKOUT  = 'ecommerce.started_checkout';
    public const DECISION_ABANDONED_CART     = 'ecommerce.abandoned_cart';
    public const DECISION_ABANDONED_CHECKOUT = 'ecommerce.abandoned_checkout';
    public const DECISION_ABANDONED_BROWSE   = 'ecommerce.abandoned_browse';

    public const CONDITION_NO_ORDER_WITHIN = 'ecommerce.no_order_within';

    /**
     * Live decision per storefront event type.
     */
    public const LIVE_DECISIONS = [
        EcommerceEvent::TYPE_VIEWED_PRODUCT   => self::DECISION_VIEWED_PRODUCT,
        EcommerceEvent::TYPE_ADDED_TO_CART    => self::DECISION_ADDED_TO_CART,
        EcommerceEvent::TYPE_STARTED_CHECKOUT => self::DECISION_STARTED_CHECKOUT,
    ];

    /**
     * Abandonment decision per storefront event type.
     */
    public const ABANDONMENT_DECISIONS = [
        EcommerceEvent::TYPE_VIEWED_PRODUCT   => self::DECISION_ABANDONED_BROWSE,
        EcommerceEvent::TYPE_ADDED_TO_CART    => self::DECISION_ABANDONED_CART,
        EcommerceEvent::TYPE_STARTED_CHECKOUT => self::DECISION_ABANDONED_CHECKOUT,
    ];

    /**
     * Orders placed longer ago than this never trigger campaigns, so historical imports send nothing.
     */
    public const MAX_TRIGGER_AGE = '-1 day';

    public function __construct(
        private RealTimeExecutioner $realTimeExecutioner,
        private ContactTracker $contactTracker,
        private PurchaseHistoryQuery $purchaseHistoryQuery,
        private LoggerInterface $logger,
        private EcommerceOrderRepository $orderRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD               => ['onCampaignBuild', 0],
            EcommerceEvents::ORDER_RECORDED                 => ['onOrderRecorded', 0],
            EcommerceEvents::STOREFRONT_EVENT_RECORDED      => ['onStorefrontEventRecorded', 0],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION   => [
                ['onOrderPlacedDecision', 0],
                ['onStorefrontDecision', 0],
            ],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION  => [
                ['onPurchaseHistoryCondition', 0],
                ['onNoOrderWithinCondition', 0],
            ],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addDecision(self::DECISION_ORDER_PLACED, [
            'label'       => 'mautic.plugin.ecommerce.campaign.order_placed',
            'description' => 'mautic.plugin.ecommerce.campaign.order_placed.descr',
            'formType'    => OrderPlacedDecisionType::class,
            'eventName'   => EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION,
        ]);

        foreach ([...self::LIVE_DECISIONS, ...self::ABANDONMENT_DECISIONS] as $decision) {
            $event->addDecision($decision, [
                'label'       => 'mautic.plugin.'.$decision,
                'description' => 'mautic.plugin.'.$decision.'.descr',
                'formType'    => StorefrontDecisionType::class,
                'eventName'   => EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION,
            ]);
        }

        $event->addCondition(self::CONDITION_NO_ORDER_WITHIN, [
            'label'       => 'mautic.plugin.ecommerce.campaign.no_order_within',
            'description' => 'mautic.plugin.ecommerce.campaign.no_order_within.descr',
            'formType'    => NoOrderWithinConditionType::class,
            'eventName'   => EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION,
        ]);

        $event->addCondition(self::CONDITION_PURCHASE_HISTORY, [
            'label'       => 'mautic.plugin.ecommerce.campaign.purchase_history',
            'description' => 'mautic.plugin.ecommerce.campaign.purchase_history.descr',
            'formType'    => PurchaseHistoryConditionType::class,
            'eventName'   => EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION,
        ]);
    }

    public function onOrderRecorded(EcommerceOrderEvent $event): void
    {
        $order = $event->getOrder();
        $lead  = $order->getLead();

        if (!$lead instanceof Lead || !$lead->getId()) {
            return;
        }

        if (!$order->isCounted()) {
            $this->logger->debug('Ecommerce order arrived cancelled or refunded; campaigns not triggered.', ['order_id' => $order->getOrderId()]);

            return;
        }

        if ($order->getOrderDate() < new \DateTimeImmutable(self::MAX_TRIGGER_AGE)) {
            $this->logger->debug('Ecommerce order is too old to trigger campaigns.', ['order_id' => $order->getOrderId()]);

            return;
        }

        $this->runDecision(self::DECISION_ORDER_PLACED, $lead, $order, $order->getId());
    }

    public function onStorefrontEventRecorded(StorefrontEventRecordedEvent $recorded): void
    {
        $event = $recorded->getStorefrontEvent();

        // Like orders: late or replayed events never trigger campaigns.
        if ($event->getOccurredAt() < new \DateTimeImmutable(self::MAX_TRIGGER_AGE)) {
            return;
        }

        $this->runDecision(self::LIVE_DECISIONS[$event->getEventType()], $event->getLead(), $event, $event->getId());
    }

    /**
     * Called by the abandonment detector for an event that has been abandoned.
     *
     * @return bool false when the campaign run failed (already logged)
     */
    public function triggerAbandonment(EcommerceEvent $event): bool
    {
        return $this->runDecision(self::ABANDONMENT_DECISIONS[$event->getEventType()], $event->getLead(), $event, $event->getId());
    }

    public function onStorefrontDecision(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        foreach ([...self::LIVE_DECISIONS, ...self::ABANDONMENT_DECISIONS] as $decision) {
            if ($event->checkContext($decision)) {
                $storefrontEvent = $event->getEventDetails();

                return $event->setResult($storefrontEvent instanceof EcommerceEvent && StorefrontEventMatcher::matches($storefrontEvent, (array) $event->getConfig()));
            }
        }

        return null;
    }

    public function onNoOrderWithinCondition(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        if (!$event->checkContext(self::CONDITION_NO_ORDER_WITHIN)) {
            return null;
        }

        $hours = max(1, (int) (((array) $event->getConfig())['hours'] ?? 24));
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return $event->setResult(!$this->orderRepository->hasCountedOrderSince((int) $event->getLead()->getId(), $since));
    }

    /**
     * Webhook, API and cron triggers have no tracked visitor; run the decision for the contact.
     *
     * The order or event is already stored when this runs, so a failing campaign must not fail the
     * request: the shop would retry, the retry is ignored as a duplicate and the campaign never runs.
     * The failure is logged with enough context to find the contact and the order or event.
     *
     * @return bool false when the campaign run failed
     */
    private function runDecision(string $decision, Lead $lead, object $passthrough, ?int $channelId): bool
    {
        if (!$lead->getId()) {
            return true;
        }

        $this->contactTracker->setSystemContact($lead);

        try {
            $this->realTimeExecutioner->execute($decision, $passthrough, 'ecommerce', $channelId);
        } catch (\Throwable $exception) {
            $this->logger->error('Ecommerce campaign decision could not be run: '.$exception->getMessage(), [
                'decision'   => $decision,
                'contact_id' => $lead->getId(),
                'channel_id' => $channelId,
                'exception'  => $exception,
            ]);

            return false;
        } finally {
            $this->contactTracker->setSystemContact(null);
        }

        return true;
    }

    public function onOrderPlacedDecision(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        if (!$event->checkContext(self::DECISION_ORDER_PLACED)) {
            return null;
        }

        $order = $event->getEventDetails();

        return $event->setResult($order instanceof EcommerceOrder && self::orderMatches($order, (array) $event->getConfig()));
    }

    public function onPurchaseHistoryCondition(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        if (!$event->checkContext(self::CONDITION_PURCHASE_HISTORY)) {
            return null;
        }

        $config = (array) $event->getConfig();
        $lead   = $event->getLead();

        try {
            $criteria = new PurchaseCriteria(
                (string) ($config['metric'] ?? PurchaseCriteria::METRIC_SPEND),
                (string) ($config['scope'] ?? PurchaseCriteria::SCOPE_ANY),
                PurchaseCriteria::splitList(isset($config['values']) ? (string) $config['values'] : null),
                (int) ($config['days'] ?? 0),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning('Invalid purchase history condition: '.$exception->getMessage());

            return $event->setResult(false);
        }

        $actual = $this->purchaseHistoryQuery->aggregateForLead($criteria, (int) $lead->getId());

        return $event->setResult(self::compare($actual, (string) ($config['operator'] ?? 'gte'), (float) ($config['value'] ?? 0)));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function orderMatches(EcommerceOrder $order, array $config): bool
    {
        if (isset($config['min_total']) && '' !== $config['min_total'] && (float) $order->getNetTotal() < (float) $config['min_total']) {
            return false;
        }

        $sources = self::lowerList($config['sources'] ?? null);
        if ([] !== $sources && !in_array(mb_strtolower($order->getOrderSource()), $sources, true)) {
            return false;
        }

        $categories = self::lowerList($config['categories'] ?? null);
        $products   = self::lowerList($config['products'] ?? null);

        if ([] === $categories && [] === $products) {
            return true;
        }

        $hasCategory = [] === $categories;
        $hasProduct  = [] === $products;

        foreach ($order->getItems() as $item) {
            $hasCategory = $hasCategory || in_array(mb_strtolower((string) $item->getCategory()), $categories, true);
            $hasProduct  = $hasProduct
                || in_array(mb_strtolower($item->getItemId()), $products, true)
                || in_array(mb_strtolower($item->getItemName()), $products, true);
        }

        return $hasCategory && $hasProduct;
    }

    public static function compare(float $actual, string $operator, float $expected): bool
    {
        $actual   = round($actual, 4);
        $expected = round($expected, 4);

        return match ($operator) {
            'gt'    => $actual > $expected,
            'lte'   => $actual <= $expected,
            'lt'    => $actual < $expected,
            'eq'    => $actual === $expected,
            default => $actual >= $expected,
        };
    }

    /**
     * @return list<string>
     */
    private static function lowerList(mixed $list): array
    {
        return array_map(
            static fn (string $value): string => mb_strtolower($value),
            PurchaseCriteria::splitList(is_scalar($list) ? (string) $list : null)
        );
    }
}
