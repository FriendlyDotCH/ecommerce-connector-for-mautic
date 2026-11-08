<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EcommerceProduct>
 */
class EcommerceProductRepository extends CommonRepository
{
    private const COLUMNS = [
        'sku', 'name', 'url', 'image_url', 'price', 'regular_price', 'sale_price', 'currency',
        'category', 'categories', 'variation_ids', 'stock_status', 'stock_quantity', 'status', 'date_modified',
    ];

    /**
     * Inserts or updates a product by store and external ID.
     *
     * @param array<string, mixed> $product normalized product (see StorePlatformInterface::mapProduct)
     */
    public function upsertProduct(string $store, array $product): void
    {
        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $values = ['store' => $store, 'external_id' => (string) $product['external_id'], 'date_added' => $now, 'date_updated' => $now];

        foreach (self::COLUMNS as $column) {
            $value           = $product[$column] ?? null;
            $values[$column] = is_array($value) ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE) : $value;
        }

        $columns = array_keys($values);
        $updates = array_map(
            static fn (string $column): string => sprintf('%1$s = VALUES(%1$s)', $column),
            array_merge(self::COLUMNS, ['date_updated'])
        );

        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf(
                'INSERT INTO %secommerce_products (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                MAUTIC_TABLE_PREFIX,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
                implode(', ', $updates)
            ),
            array_values($values)
        );
    }

    public function deleteByExternalId(string $store, string $externalId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_products WHERE store = ? AND external_id = ?',
            [$store, $externalId]
        );
    }

    /**
     * The product as stored now, detached: upsertProduct() writes with SQL, so a managed entity
     * would keep showing the old state for the rest of the request.
     */
    public function findStoredState(string $store, string $externalId): ?EcommerceProduct
    {
        $product = $this->findOneByExternalId($store, $externalId);
        if (null !== $product) {
            $this->getEntityManager()->detach($product);
        }

        return $product;
    }

    public function findOneByExternalId(string $store, string $externalId): ?EcommerceProduct
    {
        return $this->findOneBy(['store' => $store, 'externalId' => $externalId]);
    }

    /**
     * The main category for an order line: by product ID, else by SKU (the line's item ID),
     * else by variation ID.
     */
    public function findCategoryForLine(string $store, ?string $productId, string $itemId): ?string
    {
        $connection = $this->getEntityManager()->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'ecommerce_products';

        foreach ([
            ['external_id = ?', $productId],
            ['sku = ?', $itemId],
            ['JSON_CONTAINS(variation_ids, JSON_QUOTE(?))', $itemId],
        ] as [$where, $value]) {
            if (null === $value || '' === $value) {
                continue;
            }

            $category = $connection->fetchOne(
                "SELECT category FROM {$table} WHERE store = ? AND category IS NOT NULL AND {$where} LIMIT 1",
                [$store, $value]
            );

            if (false !== $category && null !== $category) {
                return (string) $category;
            }
        }

        return null;
    }

    /**
     * Gives order lines without a category the category of a product, matched like
     * findCategoryForLine().
     *
     * @return list<int> IDs of the contacts whose lines changed
     */
    public function fillMissingCategories(string $store, string $externalId): array
    {
        $product = $this->findOneByExternalId($store, $externalId);
        if (null === $product || null === $product->getCategory()) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $prefix     = MAUTIC_TABLE_PREFIX;

        $match  = ['ei.product_id = ?'];
        $params = [$externalId];
        $types  = [null];

        if (null !== $product->getSku()) {
            $match[]  = 'ei.item_id = ?';
            $params[] = $product->getSku();
            $types[]  = null;
        }

        if ([] !== $product->getVariationIds()) {
            $match[]  = 'ei.item_id IN (?)';
            $params[] = $product->getVariationIds();
            $types[]  = ArrayParameterType::STRING;
        }

        $where = "eo.order_source = ? AND ei.category IS NULL AND (".implode(' OR ', $match).')';

        $leadIds = $connection->fetchFirstColumn(
            "SELECT DISTINCT ei.lead_id FROM {$prefix}ecommerce_order_items ei
             JOIN {$prefix}ecommerce_orders eo ON eo.id = ei.order_id
             WHERE {$where} AND ei.lead_id IS NOT NULL",
            [$store, ...$params],
            [null, ...$types]
        );

        $connection->executeStatement(
            "UPDATE {$prefix}ecommerce_order_items ei
             JOIN {$prefix}ecommerce_orders eo ON eo.id = ei.order_id
             SET ei.category = ?
             WHERE {$where}",
            [$product->getCategory(), $store, ...$params],
            [null, null, ...$types]
        );

        return array_map('intval', $leadIds);
    }
}
