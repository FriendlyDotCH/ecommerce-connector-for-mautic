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
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\OrderPlacedDecisionType;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\PurchaseHistoryConditionType;
use MauticPlugin\EcommerceConnectorBundle\Service\PurchaseHistoryQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CampaignSubscriber implements EventSubscriberInterface
{
    public const DECISION_ORDER_PLACED = 'ecommerce.order_placed';

    public const CONDITION_PURCHASE_HISTORY = 'ecommerce.purchase_history';

    /**
     * Orders placed longer ago than this never trigger campaigns, so historical imports send nothing.
     */
    public const MAX_TRIGGER_AGE = '-1 day';

    public function __construct(
        private RealTimeExecutioner $realTimeExecutioner,
        private ContactTracker $contactTracker,
        private PurchaseHistoryQuery $purchaseHistoryQuery,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD               => ['onCampaignBuild', 0],
            EcommerceEvents::ORDER_RECORDED                 => ['onOrderRecorded', 0],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION   => ['onOrderPlacedDecision', 0],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION  => ['onPurchaseHistoryCondition', 0],
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

        // Webhook and API orders have no tracked visitor; run the decision for the order's contact.
        $this->contactTracker->setSystemContact($lead);

        try {
            $this->realTimeExecutioner->execute(self::DECISION_ORDER_PLACED, $order, 'ecommerce', $order->getId());
        } finally {
            $this->contactTracker->setSystemContact(null);
        }
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
