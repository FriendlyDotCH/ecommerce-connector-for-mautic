<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use Psr\Log\LoggerInterface;

/**
 * Timing metrics from counted orders, written straight into the contacts table by contact-ID
 * chunks: days since last order, average days between orders and the expected next order.
 */
class CommerceMetricsCalculator
{
    public const CHUNK_SIZE = 500;

    private const COLUMNS = [
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
        $values = array_fill_keys($leadIds, array_fill_keys(self::COLUMNS, null));

        $query = $this->connection->createQueryBuilder();
        $rows  = $query->select('eo.lead_id', 'COUNT(eo.id) AS orders', 'MIN(eo.order_date) AS first_order', 'MAX(eo.order_date) AS last_order')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where($query->expr()->in('eo.lead_id', ':leadIds'))
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('leadIds', $leadIds, ArrayParameterType::INTEGER)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->groupBy('eo.lead_id')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $utc   = new \DateTimeZone('UTC');
            $first = new \DateTimeImmutable((string) $row['first_order'], $utc);
            $last  = new \DateTimeImmutable((string) $row['last_order'], $utc);
            $count = (int) $row['orders'];

            $leadValues = [
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
        $column = $this->connection->quoteIdentifier(LeadCommerceFieldDefinition::DAYS_SINCE_LAST_ORDER);

        // Contacts with orders, plus contacts whose values must be cleared.
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT lead_id FROM {$prefix}ecommerce_orders WHERE lead_id IS NOT NULL
             UNION
             SELECT id FROM {$prefix}leads WHERE {$column} IS NOT NULL"
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
