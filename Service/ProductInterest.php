<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;

/**
 * Who showed interest in a product: viewed it, had it in a cart or checkout, or bought it. A
 * product is recognised by its ID, SKU and variation IDs, as order lines and carts name it.
 */
class ProductInterest
{
    public const VIEWED    = 'viewed';
    public const CARTED    = 'carted';
    public const PURCHASED = 'purchased';

    public const TYPES = [self::VIEWED, self::CARTED, self::PURCHASED];

    /** The longest look-back a decision can choose. */
    public const MAX_DAYS = 365;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Contacts in a published campaign that uses $decision, with any interest in the product in
     * the last MAX_DAYS days. Each decision then checks its own interest types and days.
     *
     * @return list<int>
     */
    public function campaignCandidates(EcommerceProduct $product, string $decision): array
    {
        $prefix  = MAUTIC_TABLE_PREFIX;
        $members = "SELECT DISTINCT cl.lead_id FROM {$prefix}campaign_leads cl
            JOIN {$prefix}campaigns c ON c.id = cl.campaign_id AND c.is_published = 1 AND c.deleted IS NULL
            JOIN {$prefix}campaign_events ce ON ce.campaign_id = c.id AND ce.type = :decision AND ce.deleted IS NULL
            WHERE cl.manually_removed = 0";

        $since = self::since(self::MAX_DAYS);
        $ids   = self::identifiers($product);

        $interested = array_merge(
            $this->connection->fetchFirstColumn(
                "SELECT DISTINCT e.lead_id FROM {$prefix}ecommerce_events e WHERE e.occurred_at >= :since AND e.lead_id IN ({$members}) AND (".$this->eventMatch('e', $ids).')',
                ['decision' => $decision, 'since' => $since, 'ids' => $ids, 'externalId' => $product->getExternalId()] + $this->cartParams($ids),
                ['ids' => ArrayParameterType::STRING]
            ),
            $this->connection->fetchFirstColumn(
                "SELECT DISTINCT o.lead_id FROM {$prefix}ecommerce_orders o JOIN {$prefix}ecommerce_order_items i ON i.order_id = o.id
                 WHERE o.lead_id IN ({$members}) AND o.order_date >= :since AND o.status IN (:counted) AND o.order_source = :store
                   AND (i.product_id = :externalId OR i.item_id IN (:ids))",
                ['decision' => $decision, 'since' => $since, 'counted' => EcommerceOrder::COUNTED_STATUSES, 'store' => $product->getStore(), 'externalId' => $product->getExternalId(), 'ids' => $ids],
                ['counted' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING]
            ),
        );

        return array_values(array_unique(array_map('intval', $interested)));
    }

    /**
     * When the contact last showed one of the given kinds of interest within $days, or null.
     *
     * @param list<string> $types
     */
    public function latestInterest(int $leadId, EcommerceProduct $product, array $types, int $days): ?\DateTimeImmutable
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $since  = self::since(min(max($days, 1), self::MAX_DAYS));
        $ids    = self::identifiers($product);
        $dates  = [];

        $eventTypes = array_merge(
            in_array(self::VIEWED, $types, true) ? ['viewed_product'] : [],
            in_array(self::CARTED, $types, true) ? ['added_to_cart', 'started_checkout'] : [],
        );
        if ([] !== $eventTypes) {
            $dates[] = $this->connection->fetchOne(
                "SELECT MAX(e.occurred_at) FROM {$prefix}ecommerce_events e WHERE e.lead_id = :lead AND e.occurred_at >= :since AND e.event_type IN (:eventTypes) AND (".$this->eventMatch('e', $ids).')',
                ['lead' => $leadId, 'since' => $since, 'eventTypes' => $eventTypes, 'ids' => $ids, 'externalId' => $product->getExternalId()] + $this->cartParams($ids),
                ['eventTypes' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING]
            );
        }

        if (in_array(self::PURCHASED, $types, true)) {
            $dates[] = $this->lastPurchase($leadId, $product, $since);
        }

        $dates = array_filter($dates, static fn (mixed $date): bool => is_string($date) && '' !== $date);

        return [] !== $dates ? new \DateTimeImmutable(max($dates), new \DateTimeZone('UTC')) : null;
    }

    /**
     * Whether the contact bought the product after $after (then a reminder makes no sense).
     */
    public function boughtSince(int $leadId, EcommerceProduct $product, \DateTimeInterface $after): bool
    {
        return null !== $this->lastPurchase($leadId, $product, $after->format('Y-m-d H:i:s'), true);
    }

    private function lastPurchase(int $leadId, EcommerceProduct $product, string $since, bool $strictlyAfter = false): ?string
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $date   = $this->connection->fetchOne(
            "SELECT MAX(o.order_date) FROM {$prefix}ecommerce_orders o JOIN {$prefix}ecommerce_order_items i ON i.order_id = o.id
             WHERE o.lead_id = :lead AND o.order_date ".($strictlyAfter ? '>' : '>=').' :since AND o.status IN (:counted) AND o.order_source = :store
               AND (i.product_id = :externalId OR i.item_id IN (:ids))',
            ['lead' => $leadId, 'since' => $since, 'counted' => EcommerceOrder::COUNTED_STATUSES, 'store' => $product->getStore(), 'externalId' => $product->getExternalId(), 'ids' => self::identifiers($product)],
            ['counted' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING]
        );

        return is_string($date) && '' !== $date ? $date : null;
    }

    /**
     * Product views by product ID; cart and checkout snapshots by an item's product ID, or by
     * its item ID (SKU or variation ID, for snapshots without product IDs).
     *
     * @param list<string> $ids
     */
    private function eventMatch(string $alias, array $ids): string
    {
        $cart = ["JSON_CONTAINS({$alias}.cart_items, JSON_OBJECT('product_id', :externalId))"];
        foreach (array_keys($ids) as $i) {
            $cart[] = "JSON_CONTAINS({$alias}.cart_items, JSON_OBJECT('item_id', :cartItem{$i}))";
        }

        return "({$alias}.event_type = 'viewed_product' AND {$alias}.product_id IN (:ids))"
            ." OR ({$alias}.event_type IN ('added_to_cart', 'started_checkout') AND {$alias}.cart_items IS NOT NULL AND (".implode(' OR ', $cart).'))';
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function cartParams(array $ids): array
    {
        $params = [];
        foreach ($ids as $i => $id) {
            $params['cartItem'.$i] = $id;
        }

        return $params;
    }

    /**
     * @return non-empty-list<string> external ID first, then SKU and variation IDs
     */
    public static function identifiers(EcommerceProduct $product): array
    {
        $ids = [$product->getExternalId()];
        if (null !== $product->getSku() && '' !== $product->getSku()) {
            $ids[] = $product->getSku();
        }

        return array_values(array_unique([...$ids, ...array_map('strval', $product->getVariationIds())]));
    }

    private static function since(int $days): string
    {
        return (new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
