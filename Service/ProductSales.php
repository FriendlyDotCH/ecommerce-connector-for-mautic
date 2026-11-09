<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;

/**
 * Units sold per catalog product, net of refunded units, from counted orders. An order line
 * belongs to a product of the same store by product ID, else (lines without one) by SKU,
 * product ID or variation ID in the line's item ID.
 */
class ProductSales
{
    public const DEFAULT_DAYS = 90;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $productIds catalog row IDs
     *
     * @return array<int, int> catalog row ID => units sold
     */
    public function unitsSold(array $productIds, int $days = self::DEFAULT_DAYS): array
    {
        if ([] === $productIds) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT p.id, SUM(GREATEST(i.qty - i.refunded_qty, 0)) '.$this->from().' WHERE p.id IN (:products) GROUP BY p.id',
            ['products' => $productIds, 'since' => self::since($days), 'counted' => EcommerceOrder::COUNTED_STATUSES],
            ['products' => ArrayParameterType::INTEGER, 'counted' => ArrayParameterType::STRING]
        );

        return array_map('intval', $rows);
    }

    /**
     * Catalog row IDs of the best sellers that are for sale, most units first.
     *
     * @param list<int> $excludeProductIds
     *
     * @return list<int>
     */
    public function bestSellers(int $limit, int $days = self::DEFAULT_DAYS, ?string $category = null, array $excludeProductIds = []): array
    {
        $where  = 'WHERE '.self::FOR_SALE;
        $params = ['since' => self::since($days), 'counted' => EcommerceOrder::COUNTED_STATUSES, 'exclude' => $excludeProductIds ?: [0], 'hidden' => ProductChangeDetector::HIDDEN_STATUSES];
        $types  = ['counted' => ArrayParameterType::STRING, 'exclude' => ArrayParameterType::INTEGER, 'hidden' => ArrayParameterType::STRING];

        if (null !== $category && '' !== $category) {
            $where .= ' AND (p.category = :category OR JSON_CONTAINS(p.categories, JSON_QUOTE(:category)))';
            $params['category'] = $category;
        }

        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT p.id '.$this->from().' '.$where.' AND p.id NOT IN (:exclude)
             GROUP BY p.id HAVING SUM(GREATEST(i.qty - i.refunded_qty, 0)) > 0
             ORDER BY SUM(GREATEST(i.qty - i.refunded_qty, 0)) DESC, MAX(o.order_date) DESC LIMIT '.max(1, min($limit, 50)),
            $params,
            $types
        ));
    }

    /**
     * Catalog row IDs of the newest products that are for sale: by the store's created date,
     * else (products not synced since 1.9.2) by when Mautic first saw them.
     *
     * @param list<int> $excludeProductIds
     *
     * @return list<int>
     */
    public function newArrivals(int $limit, ?string $category = null, array $excludeProductIds = []): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $where  = 'WHERE '.self::FOR_SALE.' AND p.id NOT IN (:exclude)';
        $params = ['exclude' => $excludeProductIds ?: [0], 'hidden' => ProductChangeDetector::HIDDEN_STATUSES];
        $types  = ['exclude' => ArrayParameterType::INTEGER, 'hidden' => ArrayParameterType::STRING];

        if (null !== $category && '' !== $category) {
            $where .= ' AND (p.category = :category OR JSON_CONTAINS(p.categories, JSON_QUOTE(:category)))';
            $params['category'] = $category;
        }

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id FROM {$prefix}ecommerce_products p {$where}
             ORDER BY COALESCE(p.date_created, p.date_added) DESC, p.id DESC LIMIT ".max(1, min($limit, 50)),
            $params,
            $types
        ));
    }

    /**
     * Catalog row IDs of what the contact bought, most recent first.
     *
     * @return list<int>
     */
    public function lastPurchased(int $leadId, int $limit): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT p.id '.$this->from('AND o.lead_id = :lead').' WHERE (p.status IS NULL OR p.status NOT IN (:hidden))
             GROUP BY p.id ORDER BY MAX(o.order_date) DESC LIMIT '.max(1, min($limit, 50)),
            ['lead' => $leadId, 'since' => '1970-01-01 00:00:00', 'counted' => EcommerceOrder::COUNTED_STATUSES, 'hidden' => ProductChangeDetector::HIDDEN_STATUSES],
            ['counted' => ArrayParameterType::STRING, 'hidden' => ArrayParameterType::STRING]
        ));
    }

    /**
     * The contact's categories by number of distinct products bought or viewed, most first.
     *
     * @return list<string>
     */
    public function favouriteCategories(int $leadId, int $limit = 3): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT category FROM (
                SELECT p.category, p.id ".$this->from('AND o.lead_id = :lead')." WHERE p.category IS NOT NULL
                UNION
                SELECT p.category, p.id FROM {$prefix}ecommerce_events e JOIN {$prefix}ecommerce_products p ON p.external_id = e.product_id
                WHERE e.lead_id = :lead AND e.event_type = 'viewed_product' AND p.category IS NOT NULL
             ) interest GROUP BY category ORDER BY COUNT(*) DESC, category LIMIT ".max(1, $limit),
            ['lead' => $leadId, 'since' => '1970-01-01 00:00:00', 'counted' => EcommerceOrder::COUNTED_STATUSES],
            ['counted' => ArrayParameterType::STRING]
        ));
    }

    /**
     * Catalog row IDs of products the contact viewed and that are for sale, most recent first.
     *
     * @return list<int>
     */
    public function recentlyViewed(int $leadId, int $limit): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id FROM {$prefix}ecommerce_events e JOIN {$prefix}ecommerce_products p ON p.external_id = e.product_id
             WHERE e.lead_id = :lead AND e.event_type = 'viewed_product' AND ".self::FOR_SALE.'
             GROUP BY p.id ORDER BY MAX(e.occurred_at) DESC LIMIT '.max(1, min($limit, 50)),
            ['lead' => $leadId, 'hidden' => ProductChangeDetector::HIDDEN_STATUSES],
            ['hidden' => ArrayParameterType::STRING]
        ));
    }

    public function crossSellCategory(int $leadId): ?string
    {
        $column = $this->connection->quoteIdentifier(LeadCommerceFieldDefinition::CROSS_SELL_CATEGORY);

        try {
            $category = $this->connection->fetchOne('SELECT '.$column.' FROM '.MAUTIC_TABLE_PREFIX.'leads WHERE id = ?', [$leadId]);
        } catch (\Doctrine\DBAL\Exception) {
            // The field is created on Install/Upgrade Plugins.
            return null;
        }

        return is_string($category) && '' !== $category ? $category : null;
    }

    private const FOR_SALE = "(p.stock_status IS NULL OR p.stock_status <> 'outofstock') AND (p.status IS NULL OR p.status NOT IN (:hidden))";

    private function from(string $orderCondition = ''): string
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        return "FROM {$prefix}ecommerce_products p
            JOIN {$prefix}ecommerce_orders o ON o.order_source = p.store AND o.order_date >= :since AND o.status IN (:counted) {$orderCondition}
            JOIN {$prefix}ecommerce_order_items i ON i.order_id = o.id AND (
                i.product_id = p.external_id
                OR (i.product_id IS NULL AND (i.item_id = p.external_id OR i.item_id = p.sku OR JSON_CONTAINS(p.variation_ids, JSON_QUOTE(i.item_id))))
            )";
    }

    private static function since(int $days): string
    {
        return (new \DateTimeImmutable(sprintf('-%d days', max(1, $days)), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
