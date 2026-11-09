<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use Psr\Log\LoggerInterface;

/**
 * A category the contact used to buy regularly and stopped: bought on at least MIN_ORDERS days,
 * and the last time longer ago than FACTOR times their usual gap in that category (at least
 * MIN_DAYS). With several, the one bought most often (then most recently). Written to Lapsed
 * category / Days since lapsed category.
 */
class CategoryLapseCalculator
{
    public const MIN_ORDERS = 2;

    public const FACTOR = 2.0;

    public const MIN_DAYS = 60;

    public const CHUNK_SIZE = 500;

    private const COLUMNS = [F::LAPSED_CATEGORY, F::LAPSED_CATEGORY_DAYS];

    public function __construct(
        private Connection $connection,
        private LeadColumnWriter $columnWriter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int contacts processed
     */
    public function recalculateAll(?\DateTimeInterface $now = null): int
    {
        if (!$this->columnsExist()) {
            return 0;
        }

        $column = $this->connection->quoteIdentifier(F::LAPSED_CATEGORY);
        $ids    = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT lead_id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_orders WHERE lead_id IS NOT NULL AND status IN (?) GROUP BY lead_id HAVING COUNT(*) >= ?
             UNION SELECT id FROM '.MAUTIC_TABLE_PREFIX."leads WHERE {$column} IS NOT NULL",
            [EcommerceOrder::COUNTED_STATUSES, self::MIN_ORDERS],
            [ArrayParameterType::STRING, null]
        ));
        sort($ids);

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->writeChunk($chunk, $now);
        }

        return count($ids);
    }

    /**
     * @param list<int> $leadIds
     */
    public function write(array $leadIds, ?\DateTimeInterface $now = null): void
    {
        $leadIds = array_values(array_filter(array_unique(array_map('intval', $leadIds)), static fn (int $id): bool => $id > 0));
        if ([] === $leadIds || !$this->columnsExist()) {
            return;
        }

        foreach (array_chunk($leadIds, self::CHUNK_SIZE) as $chunk) {
            $this->writeChunk($chunk, $now);
        }
    }

    /**
     * @param array<string, list<string>> $daysByCategory category => distinct purchase days (Y-m-d)
     *
     * @return array{category: string, days: int}|null
     */
    public static function lapsed(array $daysByCategory, \DateTimeInterface $now): ?array
    {
        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        $best  = null;

        foreach ($daysByCategory as $category => $days) {
            $days = array_values(array_unique($days));
            sort($days);
            $count = count($days);
            if ($count < self::MIN_ORDERS) {
                continue;
            }

            $first = new \DateTimeImmutable($days[0]);
            $last  = new \DateTimeImmutable($days[$count - 1]);
            $gap   = (int) $first->diff($last)->days / ($count - 1);
            $since = (int) $last->diff($today)->days;
            if ($last > $today || $since <= max(self::FACTOR * $gap, self::MIN_DAYS)) {
                continue;
            }

            $rank = [$count, $days[$count - 1], (string) $category];
            if (null === $best || $rank > $best['rank']) {
                $best = ['category' => (string) $category, 'days' => $since, 'rank' => $rank];
            }
        }

        return null !== $best ? ['category' => $best['category'], 'days' => $best['days']] : null;
    }

    /**
     * @param list<int> $leadIds
     */
    private function writeChunk(array $leadIds, ?\DateTimeInterface $now): void
    {
        $rows = $this->connection->iterateNumeric(
            'SELECT DISTINCT o.lead_id, i.category, DATE(o.order_date) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_orders o
             JOIN '.MAUTIC_TABLE_PREFIX."ecommerce_order_items i ON i.order_id = o.id
             WHERE o.lead_id IN (?) AND o.status IN (?) AND o.order_date IS NOT NULL
               AND i.category IS NOT NULL AND i.category <> '' AND i.qty > i.refunded_qty",
            [$leadIds, EcommerceOrder::COUNTED_STATUSES],
            [ArrayParameterType::INTEGER, ArrayParameterType::STRING]
        );

        $days = [];
        foreach ($rows as [$leadId, $category, $day]) {
            $days[(int) $leadId][(string) $category][] = (string) $day;
        }

        $now    = $now ?? new \DateTimeImmutable();
        $values = [];
        foreach ($leadIds as $leadId) {
            $lapse           = self::lapsed($days[$leadId] ?? [], $now);
            $values[$leadId] = [
                F::LAPSED_CATEGORY      => null !== $lapse ? mb_substr($lapse['category'], 0, 191) : null,
                F::LAPSED_CATEGORY_DAYS => null !== $lapse ? (string) $lapse['days'] : null,
            ];
        }

        $this->columnWriter->write(self::COLUMNS, $values);
    }

    private function columnsExist(): bool
    {
        $missing = $this->columnWriter->missingColumns(self::COLUMNS);
        if ([] !== $missing) {
            $this->logger->warning('Ecommerce lapsed category fields are missing; run Install/Upgrade Plugins.', ['columns' => $missing]);
        }

        return [] === $missing;
    }
}
