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
        'category', 'categories', 'variation_ids', 'stock_status', 'stock_quantity', 'status', 'date_modified', 'date_created',
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
            // An update without the created date (older connectors) keeps the one we have.
            static fn (string $column): string => 'date_created' === $column
                ? 'date_created = COALESCE(VALUES(date_created), date_created)'
                : sprintf('%1$s = VALUES(%1$s)', $column),
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

    /**
     * @return bool whether the product existed
     */
    public function deleteByExternalId(string $store, string $externalId): bool
    {
        return 0 < (int) $this->getEntityManager()->getConnection()->executeStatement(
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

    /**
     * One page of the catalog for the catalog list, by name.
     *
     * @param string $stock '' for all, else instock, outofstock or onbackorder
     *
     * @return array{items: list<EcommerceProduct>, total: int}
     */
    public function findCatalogPage(string $search, string $store, string $stock, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('p');

        if ('' !== $search) {
            $qb->andWhere('p.name LIKE :search OR p.sku LIKE :search OR p.category LIKE :search OR p.externalId = :exact')
                ->setParameter('search', '%'.addcslashes($search, '%_\\').'%')
                ->setParameter('exact', $search);
        }
        if ('' !== $store) {
            $qb->andWhere('p.store = :store')->setParameter('store', $store);
        }
        if ('' !== $stock) {
            $qb->andWhere('p.stockStatus = :stock')->setParameter('stock', $stock);
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        /** @var list<EcommerceProduct> $items */
        $items = $qb->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $limit))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public function findStores(): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT store FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_products ORDER BY store'
        ));
    }

    /**
     * Products for the REST API. Filters: store, category (main or any), stock_status, sku,
     * external_id, search (name, SKU or category), since (last change in Mautic). Sorted by ID,
     * or by last change when since is given, so a client can page through changes.
     *
     * @param array{store?: string, category?: string, stock_status?: string, sku?: string, external_id?: string, search?: string, since?: \DateTimeInterface} $filters
     *
     * @return array{products: list<EcommerceProduct>, total: int}
     */
    public function findForApi(array $filters, int $start, int $limit): array
    {
        $qb = $this->createQueryBuilder('p');

        foreach (['store' => 'store', 'stock_status' => 'stockStatus', 'sku' => 'sku', 'external_id' => 'externalId'] as $filter => $field) {
            if (isset($filters[$filter])) {
                $qb->andWhere(sprintf('p.%1$s = :%1$s', $field))->setParameter($field, $filters[$filter]);
            }
        }
        if (isset($filters['category'])) {
            // categories is JSON; the quoted name avoids matching "Candles" inside "Scented Candles".
            $qb->andWhere('p.category = :category OR p.categories LIKE :categoryJson')
                ->setParameter('category', $filters['category'])
                ->setParameter('categoryJson', '%'.addcslashes(json_encode($filters['category'], JSON_UNESCAPED_UNICODE) ?: '', '%_\\').'%');
        }
        if (isset($filters['search'])) {
            $qb->andWhere('p.name LIKE :search OR p.sku LIKE :search OR p.category LIKE :search')
                ->setParameter('search', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        if (isset($filters['since'])) {
            $qb->andWhere('p.dateUpdated >= :since')
                ->setParameter('since', \DateTimeImmutable::createFromInterface($filters['since'])->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
            $qb->orderBy('p.dateUpdated', 'ASC');
        }
        $qb->addOrderBy('p.id', 'ASC');

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        /** @var list<EcommerceProduct> $products */
        $products = $qb->setFirstResult($start)->setMaxResults($limit)->getQuery()->getResult();

        return ['products' => $products, 'total' => $total];
    }

    /**
     * Category names in the catalog (main and all categories), for pickers.
     *
     * @return list<string>
     */
    public function findCategoryNames(int $limit = 500): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT category, categories FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_products WHERE category IS NOT NULL OR categories IS NOT NULL'
        );

        $names = [];
        foreach ($rows as $row) {
            $decoded = is_string($row['categories']) ? json_decode($row['categories'], true) : null;
            foreach ([$row['category'], ...(is_array($decoded) ? $decoded : [])] as $name) {
                if (is_string($name) && '' !== trim($name)) {
                    $names[trim($name)] = true;
                }
            }
        }

        $names = array_map('strval', array_keys($names));
        natcasesort($names);

        return array_slice(array_values($names), 0, $limit);
    }
}
