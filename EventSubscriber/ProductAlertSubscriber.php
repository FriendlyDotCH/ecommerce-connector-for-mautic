<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\ProductAlertDecisionType;
use MauticPlugin\EcommerceConnectorBundle\DTO\ProductChange;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductChangeDetector;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductInterest;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Back in stock, price drop and low inventory: campaign decisions fired by the product alerts
 * command for contacts who showed interest in the product.
 */
class ProductAlertSubscriber implements EventSubscriberInterface
{
    public const DECISION_BACK_IN_STOCK = 'ecommerce.back_in_stock';
    public const DECISION_PRICE_DROP    = 'ecommerce.price_drop';
    public const DECISION_LOW_STOCK     = 'ecommerce.low_stock';

    /** Which detected change can fire which decision. */
    public const DECISIONS_BY_CHANGE = [
        ProductChangeDetector::BACK_IN_STOCK => self::DECISION_BACK_IN_STOCK,
        ProductChangeDetector::PRICE_DROP    => self::DECISION_PRICE_DROP,
        ProductChangeDetector::STOCK_DROP    => self::DECISION_LOW_STOCK,
    ];

    public const DEFAULT_DAYS             = 30;
    public const DEFAULT_MIN_DROP_PERCENT = 10;
    public const DEFAULT_THRESHOLD        = 5;

    public function __construct(
        private ProductInterest $interest,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD                 => ['onCampaignBuild', 0],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION     => ['onProductAlertDecision', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        foreach (self::DECISIONS_BY_CHANGE as $decision) {
            $event->addDecision($decision, [
                'label'           => 'mautic.plugin.'.$decision,
                'description'     => 'mautic.plugin.'.$decision.'.descr',
                'formType'        => ProductAlertDecisionType::class,
                'formTypeOptions' => ['alert' => $decision],
                'eventName'       => EcommerceEvents::ON_CAMPAIGN_TRIGGER_DECISION,
            ]);
        }
    }

    public function onProductAlertDecision(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        foreach (self::DECISIONS_BY_CHANGE as $changeType => $decision) {
            if (!$event->checkContext($decision)) {
                continue;
            }

            $change = $event->getEventDetails();
            if (!$change instanceof ProductChange || $change->type !== $changeType) {
                return $event->setResult(false);
            }

            $matched = $this->matches($change, (array) $event->getConfig(), (int) $event->getLead()->getId(), $decision);
            $change->matched = $change->matched || $matched;

            return $event->setResult($matched);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function matches(ProductChange $change, array $config, int $leadId, string $decision): bool
    {
        if (!self::thresholdMet($change, $config, $decision) || !self::productMatches($change, $config)) {
            return false;
        }

        $types = array_values(array_intersect(ProductInterest::TYPES, (array) ($config['interests'] ?? [ProductInterest::VIEWED, ProductInterest::CARTED])));
        $days  = (int) ($config['days'] ?? self::DEFAULT_DAYS) ?: self::DEFAULT_DAYS;
        if ([] === $types) {
            return false;
        }

        $minViews = max(1, (int) ($config['min_views'] ?? 1));

        // Interest shown after the change (a view of the restocked product) does not count.
        $latest = $this->interest->latestInterest($leadId, $change->product, $types, $days, $minViews, $change->detectedAt);
        if (null === $latest) {
            return false;
        }

        // Viewed or carted, then bought it anyway: no reminder. Purchase-based audiences
        // (restock of something they buy again) are reminded regardless.
        return in_array(ProductInterest::PURCHASED, $types, true)
            || !$this->interest->boughtSince($leadId, $change->product, $latest);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function thresholdMet(ProductChange $change, array $config, string $decision): bool
    {
        $old = null !== $change->oldValue ? (float) $change->oldValue : null;
        $new = null !== $change->newValue ? (float) $change->newValue : null;

        return match ($decision) {
            self::DECISION_PRICE_DROP => null !== $old && null !== $new && $old > 0
                && round(($old - $new) / $old * 100, 2) >= (float) ($config['min_drop_percent'] ?? self::DEFAULT_MIN_DROP_PERCENT),
            // Fires once when stock crosses the threshold, not on every sale below it.
            self::DECISION_LOW_STOCK => null !== $old && null !== $new
                && $new <= ($threshold = max(1, (int) ($config['threshold'] ?? self::DEFAULT_THRESHOLD))) && $old > $threshold,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function productMatches(ProductChange $change, array $config): bool
    {
        $lower      = static fn (mixed $list): array => array_map('mb_strtolower', PurchaseCriteria::splitList(is_scalar($list) ? (string) $list : null));
        $categories = $lower($config['categories'] ?? null);
        $products   = $lower($config['products'] ?? null);
        $product    = $change->product;

        $productCategories = array_map('mb_strtolower', array_filter([$product->getCategory(), ...$product->getCategories()], 'is_string'));
        $identifiers       = array_map('mb_strtolower', [...ProductInterest::identifiers($product), $product->getName()]);

        return ([] === $categories || [] !== array_intersect($categories, $productCategories))
            && ([] === $products || [] !== array_intersect($products, $identifiers));
    }
}
