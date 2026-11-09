<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;

/**
 * Ready-made audiences on the Opportunities page: who to contact now, how many and what one
 * order from each is typically worth. Everything is read from the computed contact fields that
 * the daily recalculation writes, so each opportunity is also a plain segment filter and a
 * segment made from it holds the same contacts as the page counts.
 */
class OpportunityFinder
{
    public const TYPE_CROSS_SELL   = 'cross_sell';
    public const TYPE_DUE_REORDER  = 'due_reorder';
    public const TYPE_SECOND_ORDER = 'second_order';
    public const TYPE_VIP_SLIPPING = 'vip_slipping';
    public const TYPE_INTERESTED   = 'interested';
    public const TYPE_LAPSED_CATEGORY = 'lapsed_category';

    public const TYPES = [self::TYPE_CROSS_SELL, self::TYPE_DUE_REORDER, self::TYPE_SECOND_ORDER, self::TYPE_VIP_SLIPPING, self::TYPE_INTERESTED, self::TYPE_LAPSED_CATEGORY];

    /** Products or categories named in a row's description. */
    public const TOP_EXAMPLES = 3;

    /** Due to reorder: the expected next order fell within this many days, up to today. */
    public const DUE_WINDOW_DAYS = 30;

    /**
     * Big spenders: lifetime value above this percentile of all customers (the top 40%, what
     * RFM monetary 4 or 5 means when RFM counts all orders). All-time on purpose: an RFM window
     * would take the spend score away from exactly the customers who stopped buying.
     */
    public const BIG_SPENDER_PERCENTILE = 60;

    /** Repeat customers needed before the second-order window comes from the shop's own data. */
    public const SECOND_ORDER_MIN_SAMPLES = 5;

    /** Window used until then: most second orders in retail fall within these days. */
    public const SECOND_ORDER_DEFAULT_WINDOW = [20, 60];

    public function __construct(
        private Connection $connection,
        private CrossSellCalculator $crossSell,
        private CurrencyFormatter $formatter,
        private Config $config,
    ) {
    }

    /**
     * Opportunities with at least one contact, largest potential first.
     *
     * @return list<array{key: string, type: string, name: string, params: array<string, string|int>, contacts: int, typical_order: float, potential: float, filters: list<array<string, mixed>>}>
     */
    public function findAll(): array
    {
        $columns = $this->existingColumns();
        $has     = static fn (string ...$names): bool => [] === array_diff($names, $columns);

        $opportunities = array_merge(
            $has(F::CROSS_SELL_CATEGORY, F::CROSS_SELL_FROM) ? $this->crossSell() : [],
            array_filter([
                $has(F::NEXT_ORDER_DATE, F::LIFECYCLE_STAGE) ? $this->dueToReorder() : null,
                $has(F::ORDER_COUNT, F::DAYS_SINCE_LAST_ORDER) ? $this->secondOrder() : null,
                $has(F::LIFECYCLE_STAGE, F::LIFETIME_VALUE, F::ORDER_COUNT) ? $this->vipSlipping() : null,
                $has(F::INTEREST_PRODUCT) ? $this->interested() : null,
                $has(F::LAPSED_CATEGORY) ? $this->lapsedCategory() : null,
            ])
        );

        $opportunities = array_values(array_filter($opportunities, static fn (array $o): bool => $o['contacts'] > 0));
        usort($opportunities, static fn (array $a, array $b): int => [$b['potential'], $b['contacts'], $a['key']] <=> [$a['potential'], $a['contacts'], $b['key']]);

        return $opportunities;
    }

    /**
     * @return array{key: string, type: string, name: string, params: array<string, string|int>, contacts: int, typical_order: float, potential: float, filters: list<array<string, mixed>>}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->findAll() as $opportunity) {
            if ($opportunity['key'] === $key) {
                return $opportunity;
            }
        }

        return null;
    }

    /**
     * The 25th to 75th percentile of days between a customer's first and second order, or null
     * with too few repeat customers to tell.
     *
     * @param list<int|float> $gaps
     *
     * @return array{0: int, 1: int}|null
     */
    public static function secondOrderWindow(array $gaps): ?array
    {
        if (count($gaps) < self::SECOND_ORDER_MIN_SAMPLES) {
            return null;
        }

        sort($gaps);
        $from = (int) floor(self::percentile($gaps, 0.25));
        $to   = (int) ceil(self::percentile($gaps, 0.75));

        return [max(1, $from), max(max(1, $from), $to)];
    }

    /**
     * The value a customer must beat to be in the top (100 - $percentile)%, picked the same way
     * as the RFM quintile cutoffs; null without customers.
     *
     * @param list<float> $values
     */
    public static function percentileCutoff(array $values, int $percentile): ?float
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $count = count($values);

        return (float) $values[min($count - 1, (int) floor($percentile * $count / 100))];
    }

    /**
     * @param list<int|float> $sorted ascending
     */
    private static function percentile(array $sorted, float $p): float
    {
        $index = ($n = count($sorted)) > 1 ? $p * ($n - 1) : 0.0;
        $low   = (int) floor($index);
        $high  = min($n - 1, $low + 1);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($index - $low);
    }

    /**
     * One opportunity per cross-sell category, with the strongest rule behind it.
     *
     * @return list<array{key: string, type: string, name: string, params: array<string, string|int>, contacts: int, typical_order: float, potential: float, filters: list<array<string, mixed>>}>
     */
    private function crossSell(): array
    {
        $to   = $this->connection->quoteIdentifier(F::CROSS_SELL_CATEGORY);
        $from = $this->connection->quoteIdentifier(F::CROSS_SELL_FROM);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT {$to} AS category, {$from} AS source, COUNT(*) AS contacts FROM ".MAUTIC_TABLE_PREFIX."leads
             WHERE {$to} IS NOT NULL AND {$to} <> '' GROUP BY {$to}, {$from}"
        );

        $byCategory = [];
        foreach ($rows as $row) {
            $category = (string) $row['category'];
            $byCategory[$category]['contacts'] = ($byCategory[$category]['contacts'] ?? 0) + (int) $row['contacts'];
            $byCategory[$category]['sources'][(string) $row['source']] = (int) $row['contacts'];
        }

        $rules = [];
        foreach ($this->crossSell->rules() as $rule) {
            $rules[$rule['to']][$rule['from']] ??= $rule;
        }

        $typical       = $this->categoryOrderValues(array_map('strval', array_keys($byCategory)));
        $opportunities = [];
        foreach ($byCategory as $category => $group) {
            $category = (string) $category;
            arsort($group['sources']);
            $source = (string) array_key_first($group['sources']);
            $rule   = $rules[$category][$source] ?? null;

            $opportunities[] = $this->opportunity(
                self::TYPE_CROSS_SELL.':'.$category,
                self::TYPE_CROSS_SELL,
                $group['contacts'],
                $typical[$category] ?? 0.0,
                [$this->filter(F::CROSS_SELL_CATEGORY, '=', $category, 'text')],
                [
                    '%category%'   => $category,
                    '%from%'       => $source,
                    '%confidence%' => null !== $rule ? (int) round($rule['confidence'] * 100) : 0,
                    '%sources%'    => count($group['sources']),
                ],
                $category
            );
        }

        return $opportunities;
    }

    private function dueToReorder(): ?array
    {
        $next  = $this->connection->quoteIdentifier(F::NEXT_ORDER_DATE);
        $stage = $this->connection->quoteIdentifier(F::LIFECYCLE_STAGE);
        $row   = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS contacts, '.$this->typicalOrderSql().' AS typical FROM '.MAUTIC_TABLE_PREFIX."leads
             WHERE {$next} IS NOT NULL AND {$next} <= CURDATE() AND {$next} >= CURDATE() - INTERVAL ".self::DUE_WINDOW_DAYS." DAY
               AND ({$stage} IS NULL OR {$stage} <> ?)",
            [F::STAGE_LAPSED]
        ) ?: ['contacts' => 0, 'typical' => 0];

        return $this->opportunity(self::TYPE_DUE_REORDER, self::TYPE_DUE_REORDER, (int) $row['contacts'], (float) $row['typical'], [
            $this->filter(F::NEXT_ORDER_DATE, 'lte', 'today', 'date'),
            $this->filter(F::NEXT_ORDER_DATE, 'gte', '-'.self::DUE_WINDOW_DAYS.' days', 'date'),
            $this->filter(F::LIFECYCLE_STAGE, '!=', F::STAGE_LAPSED, 'select'),
        ], ['%days%' => self::DUE_WINDOW_DAYS]);
    }

    private function secondOrder(): ?array
    {
        $window   = self::secondOrderWindow($this->secondOrderGaps());
        $measured = null !== $window;
        [$from, $to] = $window ?? self::SECOND_ORDER_DEFAULT_WINDOW;

        $count = $this->connection->quoteIdentifier(F::ORDER_COUNT);
        $days  = $this->connection->quoteIdentifier(F::DAYS_SINCE_LAST_ORDER);
        $row   = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS contacts, '.$this->typicalOrderSql().' AS typical FROM '.MAUTIC_TABLE_PREFIX."leads
             WHERE {$count} = 1 AND {$days} BETWEEN ? AND ?",
            [$from, $to]
        ) ?: ['contacts' => 0, 'typical' => 0];

        return $this->opportunity(self::TYPE_SECOND_ORDER, self::TYPE_SECOND_ORDER, (int) $row['contacts'], (float) $row['typical'], [
            $this->filter(F::ORDER_COUNT, '=', '1', 'number'),
            $this->filter(F::DAYS_SINCE_LAST_ORDER, 'gte', (string) $from, 'number'),
            $this->filter(F::DAYS_SINCE_LAST_ORDER, 'lte', (string) $to, 'number'),
        ], ['%from%' => $from, '%to%' => $to, '%basis%' => $measured ? 'measured' : 'default']);
    }

    private function vipSlipping(): ?array
    {
        $stage  = $this->connection->quoteIdentifier(F::LIFECYCLE_STAGE);
        $value  = $this->connection->quoteIdentifier(F::LIFETIME_VALUE);
        $count  = $this->connection->quoteIdentifier(F::ORDER_COUNT);
        $values = array_map('floatval', $this->connection->fetchFirstColumn(
            'SELECT '.$value.' FROM '.MAUTIC_TABLE_PREFIX."leads WHERE {$count} > 0 AND {$value} IS NOT NULL"
        ));
        $cutoff = self::percentileCutoff($values, self::BIG_SPENDER_PERCENTILE);
        if (null === $cutoff) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS contacts, '.$this->typicalOrderSql().' AS typical FROM '.MAUTIC_TABLE_PREFIX."leads
             WHERE {$stage} = ? AND {$count} > 0 AND {$value} > ?",
            [F::STAGE_AT_RISK, $cutoff]
        ) ?: ['contacts' => 0, 'typical' => 0];

        // The segment keeps this amount: a fixed, readable filter.
        return $this->opportunity(self::TYPE_VIP_SLIPPING, self::TYPE_VIP_SLIPPING, (int) $row['contacts'], (float) $row['typical'], [
            $this->filter(F::LIFECYCLE_STAGE, '=', F::STAGE_AT_RISK, 'select'),
            $this->filter(F::LIFETIME_VALUE, 'gt', number_format($cutoff, 2, '.', ''), 'number'),
        ], ['%amount%' => $this->formatter->format($this->config->getBaseCurrency(), $cutoff)]);
    }

    private function interested(): array
    {
        $product = $this->connection->quoteIdentifier(F::INTEREST_PRODUCT);
        $top     = $this->top(F::INTEREST_PRODUCT);
        // Worth: the price of the product they are interested in, from the catalog.
        $row     = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS contacts, COALESCE(AVG(p.price), 0) AS typical FROM '.MAUTIC_TABLE_PREFIX."leads l
             LEFT JOIN (SELECT name, MAX(price) AS price FROM ".MAUTIC_TABLE_PREFIX."ecommerce_products GROUP BY name) p ON p.name = l.{$product}
             WHERE l.{$product} IS NOT NULL AND l.{$product} <> ''"
        ) ?: ['contacts' => 0, 'typical' => 0];

        return $this->opportunity(self::TYPE_INTERESTED, self::TYPE_INTERESTED, (int) $row['contacts'], (float) $row['typical'], [
            $this->filter(F::INTEREST_PRODUCT, '!empty', '', 'text'),
        ], ['%days%' => InterestCalculator::DAYS, '%examples%' => $top]);
    }

    private function lapsedCategory(): array
    {
        $category = $this->connection->quoteIdentifier(F::LAPSED_CATEGORY);
        $row      = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS contacts, '.$this->typicalOrderSql().' AS typical FROM '.MAUTIC_TABLE_PREFIX."leads
             WHERE {$category} IS NOT NULL AND {$category} <> ''"
        ) ?: ['contacts' => 0, 'typical' => 0];

        return $this->opportunity(self::TYPE_LAPSED_CATEGORY, self::TYPE_LAPSED_CATEGORY, (int) $row['contacts'], (float) $row['typical'], [
            $this->filter(F::LAPSED_CATEGORY, '!empty', '', 'text'),
        ], ['%factor%' => (int) CategoryLapseCalculator::FACTOR, '%min%' => CategoryLapseCalculator::MIN_DAYS, '%examples%' => $this->top(F::LAPSED_CATEGORY)]);
    }

    /**
     * The most common values of a field, with counts: "Rose gift set (4), Bath bomb (2)".
     */
    private function top(string $field): string
    {
        $column = $this->connection->quoteIdentifier($field);
        $rows   = $this->connection->fetchAllNumeric(
            "SELECT {$column}, COUNT(*) AS n FROM ".MAUTIC_TABLE_PREFIX."leads
             WHERE {$column} IS NOT NULL AND {$column} <> '' GROUP BY {$column} ORDER BY n DESC, {$column} LIMIT ".self::TOP_EXAMPLES
        );

        return implode(', ', array_map(static fn (array $row): string => sprintf('%s (%d)', $row[0], $row[1]), $rows));
    }

    /**
     * Days between first and second counted order, per repeat customer.
     *
     * @return list<int>
     */
    private function secondOrderGaps(): array
    {
        $statuses = implode(', ', array_map(fn (string $s): string => $this->connection->quote($s), EcommerceOrder::COUNTED_STATUSES));

        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DATEDIFF(MAX(order_date), MIN(order_date)) FROM (
                SELECT lead_id, order_date, ROW_NUMBER() OVER (PARTITION BY lead_id ORDER BY order_date, id) AS n
                FROM '.MAUTIC_TABLE_PREFIX."ecommerce_orders
                WHERE lead_id IS NOT NULL AND order_date IS NOT NULL AND status IN ({$statuses})
             ) numbered WHERE n <= 2 GROUP BY lead_id HAVING COUNT(*) = 2"
        ));
    }

    /**
     * Average net order value in base currency of counted orders containing each category.
     *
     * @param list<string> $categories
     *
     * @return array<string, float>
     */
    private function categoryOrderValues(array $categories): array
    {
        if ([] === $categories) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT category, AVG(total) FROM (
                SELECT DISTINCT eoi.category, eo.id, '.EcommerceOrder::baseNetSql().' AS total
                FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items eoi
                JOIN '.MAUTIC_TABLE_PREFIX.'ecommerce_orders eo ON eo.id = eoi.order_id
                WHERE eoi.category IN (?) AND eo.status IN (?)
             ) orders GROUP BY category',
            [$categories, EcommerceOrder::COUNTED_STATUSES],
            [Connection::PARAM_STR_ARRAY, Connection::PARAM_STR_ARRAY]
        );

        return array_map('floatval', $rows);
    }

    /**
     * SQL for the average of each contact's average order value (lifetime value per order).
     */
    private function typicalOrderSql(): string
    {
        $value = $this->connection->quoteIdentifier(F::LIFETIME_VALUE);
        $count = $this->connection->quoteIdentifier(F::ORDER_COUNT);

        return "COALESCE(AVG(CASE WHEN {$count} > 0 THEN {$value} / {$count} END), 0)";
    }

    /**
     * @return array<string, mixed>
     */
    private function filter(string $field, string $operator, string $value, string $type): array
    {
        return [
            'glue'       => 'and',
            'field'      => $field,
            'object'     => 'lead',
            'type'       => $type,
            'operator'   => $operator,
            'properties' => ['filter' => $value],
        ];
    }

    /**
     * @param list<array<string, mixed>>  $filters
     * @param array<string, string|int>   $params
     *
     * @return array{key: string, type: string, name: string, params: array<string, string|int>, contacts: int, typical_order: float, potential: float, filters: list<array<string, mixed>>}
     */
    private function opportunity(string $key, string $type, int $contacts, float $typical, array $filters, array $params, string $name = ''): array
    {
        return [
            'key'           => $key,
            'type'          => $type,
            'name'          => $name,
            'params'        => $params,
            'contacts'      => $contacts,
            'typical_order' => round($typical, 2),
            'potential'     => round($contacts * $typical, 2),
            'filters'       => $filters,
        ];
    }

    /**
     * Computed fields present on the contacts table; an opportunity needs its fields installed.
     *
     * @return list<string>
     */
    private function existingColumns(): array
    {
        return array_map('strval', array_keys(array_change_key_case($this->connection->createSchemaManager()->listTableColumns(MAUTIC_TABLE_PREFIX.'leads'))));
    }
}
