<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use Psr\Log\LoggerInterface;

/**
 * Commerce fields from counted orders, written straight into the contacts table by contact-ID
 * chunks: lifetime value, order count, last order (in base currency), days since last order,
 * average days between orders and the expected next order.
 */
class CommerceMetricsCalculator
{
    public const CHUNK_SIZE = 500;

    private const COLUMNS = [
        LeadCommerceFieldDefinition::LIFETIME_VALUE,
        LeadCommerceFieldDefinition::ORDER_COUNT,
        LeadCommerceFieldDefinition::LAST_ORDER_TOTAL,
        LeadCommerceFieldDefinition::LAST_ORDER_DATE,
        LeadCommerceFieldDefinition::DAYS_SINCE_LAST_ORDER,
        LeadCommerceFieldDefinition::AVG_DAYS_BETWEEN_ORDERS,
        LeadCommerceFieldDefinition::NEXT_ORDER_DATE,
    ];

    public function __construct(
        private Connection $connection,
        private LeadColumnWriter $columnWriter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, array<string, int|float|string|null>> lead ID => column => value
     */
    public function compute(array $leadIds, ?\DateTimeInterface $now = null): array
    {
        $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), static fn (int $id): bool => $id > 0)));
        if ([] === $leadIds) {
            return [];
        }

        $today  = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $empty  = array_fill_keys(self::COLUMNS, null);
        $empty[LeadCommerceFieldDefinition::LIFETIME_VALUE]   = '0';
        $empty[LeadCommerceFieldDefinition::ORDER_COUNT]      = 0;
        $empty[LeadCommerceFieldDefinition::LAST_ORDER_TOTAL] = '0';
        $values = array_fill_keys($leadIds, $empty);

        $query = $this->connection->createQueryBuilder();
        $rows  = $query->select('eo.lead_id', 'COUNT(eo.id) AS orders', 'MIN(eo.order_date) AS first_order', 'MAX(eo.order_date) AS last_order', 'SUM('.EcommerceOrder::baseNetSql().') AS lifetime_value')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where($query->expr()->in('eo.lead_id', ':leadIds'))
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('leadIds', $leadIds, ArrayParameterType::INTEGER)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->groupBy('eo.lead_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $lastTotals = $this->lastOrderTotals($leadIds);

        foreach ($rows as $row) {
            $utc   = new \DateTimeZone('UTC');
            $first = new \DateTimeImmutable((string) $row['first_order'], $utc);
            $last  = new \DateTimeImmutable((string) $row['last_order'], $utc);
            $count = (int) $row['orders'];

            $leadValues = [
                LeadCommerceFieldDefinition::LIFETIME_VALUE          => number_format((float) $row['lifetime_value'], 2, '.', ''),
                LeadCommerceFieldDefinition::ORDER_COUNT             => $count,
                LeadCommerceFieldDefinition::LAST_ORDER_TOTAL        => $lastTotals[(int) $row['lead_id']] ?? '0',
                LeadCommerceFieldDefinition::LAST_ORDER_DATE         => $last->format('Y-m-d H:i:s'),
                LeadCommerceFieldDefinition::DAYS_SINCE_LAST_ORDER   => max(0, (int) floor(($today->getTimestamp() - $last->getTimestamp()) / 86400)),
                LeadCommerceFieldDefinition::AVG_DAYS_BETWEEN_ORDERS => null,
                LeadCommerceFieldDefinition::NEXT_ORDER_DATE         => null,
            ];

            // A rhythm needs at least two orders on different days.
            if ($count > 1 && $last > $first) {
                $averageDays = ($last->getTimestamp() - $first->getTimestamp()) / 86400 / ($count - 1);

                $leadValues[LeadCommerceFieldDefinition::AVG_DAYS_BETWEEN_ORDERS] = round($averageDays, 1);
                $leadValues[LeadCommerceFieldDefinition::NEXT_ORDER_DATE]         = $last
                    ->modify(sprintf('+%d seconds', (int) round($averageDays * 86400)))
                    ->format('Y-m-d');
            }

            $values[(int) $row['lead_id']] = $leadValues;
        }

        return $values;
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, string> lead ID => net total of the latest counted order, in base currency
     */
    private function lastOrderTotals(array $leadIds): array
    {
        $query = $this->connection->createQueryBuilder();
        $rows  = $query->select('eo.lead_id', EcommerceOrder::baseNetSql().' AS total')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where($query->expr()->in('eo.lead_id', ':leadIds'))
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('leadIds', $leadIds, ArrayParameterType::INTEGER)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->orderBy('eo.lead_id')
            ->addOrderBy('eo.order_date', 'DESC')
            ->addOrderBy('eo.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['lead_id']] ??= number_format((float) $row['total'], 2, '.', '');
        }

        return $totals;
    }

    /**
     * @param int[] $leadIds
     */
    public function write(array $leadIds, ?\DateTimeInterface $now = null): void
    {
        if ($this->columnsExist()) {
            $this->columnWriter->write(self::COLUMNS, $this->compute($leadIds, $now));
        }
    }

    /**
     * @return int number of contacts processed
     */
    public function recalculateAll(?\DateTimeInterface $now = null): int
    {
        if (!$this->columnsExist()) {
            return 0;
        }

        $prefix = MAUTIC_TABLE_PREFIX;
        $column = $this->connection->quoteIdentifier(LeadCommerceFieldDefinition::ORDER_COUNT);

        // Contacts with orders, plus contacts whose values must be cleared.
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT lead_id FROM {$prefix}ecommerce_orders WHERE lead_id IS NOT NULL
             UNION
             SELECT id FROM {$prefix}leads WHERE {$column} > 0"
        ));
        sort($ids);

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->write($chunk, $now);
        }

        return count($ids);
    }

    private function columnsExist(): bool
    {
        $missing = $this->columnWriter->missingColumns(self::COLUMNS);
        if ([] !== $missing) {
            $this->logger->warning('Ecommerce timing fields are missing; run Install/Upgrade Plugins.', ['columns' => $missing]);
        }

        return [] === $missing;
    }
}
