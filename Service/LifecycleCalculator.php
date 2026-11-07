<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lifecycle stage and RFM scores from counted orders.
 *
 * Stages, first match wins: lapsed (no order for lapsed_days), at risk (overdue against the
 * contact's own rhythm, or a single order older than single_order_days), VIP (top
 * vip_top_percent by net spend, or vip_min_orders orders), new (one order), active.
 *
 * RFM scores are 1-5 by quintile across all customers. The shop-wide cutoffs come from the
 * daily run and are cached, so one contact can be scored right after an order.
 */
class LifecycleCalculator
{
    public const CHUNK_SIZE = 500;

    /**
     * The top-percent VIP rule needs enough customers to mean anything.
     */
    public const VIP_PERCENT_MIN_CUSTOMERS = 10;

    private const CACHE_KEY = 'ecommerce_lifecycle_thresholds';

    private const COLUMNS = [F::LIFECYCLE_STAGE, F::RFM_SCORE, F::RFM_RECENCY, F::RFM_FREQUENCY, F::RFM_MONETARY];

    public function __construct(
        private Connection $connection,
        private LeadColumnWriter $columnWriter,
        private Config $config,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array{orders: int, days_since: int, avg_gap: ?float, monetary: float} $customer
     * @param array<string, float>                                                $settings
     */
    public static function stage(array $customer, array $settings, ?float $vipCutoff): string
    {
        $days = $customer['days_since'];

        if ($days > $settings['lifecycle_lapsed_days']) {
            return F::STAGE_LAPSED;
        }

        $overdue = 1 === $customer['orders']
            ? $days > $settings['lifecycle_single_order_days']
            : null !== $customer['avg_gap'] && $days > max($settings['lifecycle_at_risk_factor'] * $customer['avg_gap'], $settings['lifecycle_at_risk_min_days']);

        if ($overdue) {
            return F::STAGE_AT_RISK;
        }

        if ((null !== $vipCutoff && $customer['monetary'] >= $vipCutoff)
            || ($settings['lifecycle_vip_min_orders'] > 0 && $customer['orders'] >= $settings['lifecycle_vip_min_orders'])) {
            return F::STAGE_VIP;
        }

        return 1 === $customer['orders'] ? F::STAGE_NEW : F::STAGE_ACTIVE;
    }

    /**
     * 1-5: one point plus one per quintile cutoff beaten. Ties with a cutoff do not beat it,
     * so the many one-order customers do not all score 5 on frequency.
     *
     * @param list<float> $cutoffs ascending 20th, 40th, 60th and 80th percentile
     */
    public static function quintileScore(float $value, array $cutoffs, bool $lowerIsBetter = false): int
    {
        $score = 1;
        foreach ($cutoffs as $cutoff) {
            if ($lowerIsBetter ? $value < $cutoff : $value > $cutoff) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * @param list<float> $values
     *
     * @return list<float> 20th, 40th, 60th and 80th percentile
     */
    public static function quintileCutoffs(array $values): array
    {
        if ([] === $values) {
            return [];
        }

        sort($values);
        $count = count($values);

        return array_map(static fn (int $k): float => (float) $values[min($count - 1, (int) floor($k * $count / 5))], [1, 2, 3, 4]);
    }

    /**
     * @param int[] $leadIds
     */
    public function write(array $leadIds, ?\DateTimeInterface $now = null): void
    {
        if (!$this->columnsExist()) {
            return;
        }

        $customers  = $this->loadCustomers($leadIds, $now);
        $thresholds = $this->getThresholds($now);
        $settings   = $this->config->getLifecycleSettings();
        $rows       = [];

        foreach (array_unique(array_map('intval', $leadIds)) as $leadId) {
            $customer = $customers[$leadId] ?? null;
            if (null === $customer || $leadId <= 0) {
                $rows[$leadId] = array_fill_keys(self::COLUMNS, null);
                continue;
            }

            $recency   = [] !== $thresholds['recency'] ? self::quintileScore($customer['days_since'], $thresholds['recency'], true) : null;
            $frequency = [] !== $thresholds['frequency'] ? self::quintileScore($customer['orders'], $thresholds['frequency']) : null;
            $monetary  = [] !== $thresholds['monetary'] ? self::quintileScore($customer['monetary'], $thresholds['monetary']) : null;

            $rows[$leadId] = [
                F::LIFECYCLE_STAGE => self::stage($customer, $settings, $thresholds['vip_cutoff']),
                F::RFM_SCORE       => null !== $recency ? $recency.$frequency.$monetary : null,
                F::RFM_RECENCY     => $recency,
                F::RFM_FREQUENCY   => $frequency,
                F::RFM_MONETARY    => $monetary,
            ];
        }

        unset($rows[0]);
        $this->columnWriter->write(self::COLUMNS, $rows);
    }

    /**
     * Refreshes the shop-wide cutoffs, then scores every customer.
     *
     * @return int number of contacts processed
     */
    public function recalculateAll(?\DateTimeInterface $now = null): int
    {
        if (!$this->columnsExist()) {
            return 0;
        }

        $this->refreshThresholds($now);

        $prefix = MAUTIC_TABLE_PREFIX;
        $column = $this->connection->quoteIdentifier(F::LIFECYCLE_STAGE);
        $ids    = array_map('intval', $this->connection->fetchFirstColumn(
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

    /**
     * @return array{vip_cutoff: ?float, recency: list<float>, frequency: list<float>, monetary: list<float>}
     */
    public function refreshThresholds(?\DateTimeInterface $now = null): array
    {
        $customers = $this->loadCustomers(null, $now);
        $monetary  = array_column($customers, 'monetary');
        $settings  = $this->config->getLifecycleSettings();

        $vipCutoff = null;
        if (count($monetary) >= self::VIP_PERCENT_MIN_CUSTOMERS && $settings['lifecycle_vip_top_percent'] > 0) {
            $sorted    = $monetary;
            rsort($sorted);
            $topCount  = max(1, (int) ceil(count($sorted) * $settings['lifecycle_vip_top_percent'] / 100));
            $vipCutoff = (float) $sorted[$topCount - 1];
        }

        $thresholds = [
            'vip_cutoff' => $vipCutoff,
            'recency'    => self::quintileCutoffs(array_map('floatval', array_column($customers, 'days_since'))),
            'frequency'  => self::quintileCutoffs(array_map('floatval', array_column($customers, 'orders'))),
            'monetary'   => self::quintileCutoffs($monetary),
        ];

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($thresholds);
        $item->expiresAfter(3 * 86400);
        $this->cache->save($item);

        return $thresholds;
    }

    /**
     * @return array{vip_cutoff: ?float, recency: list<float>, frequency: list<float>, monetary: list<float>}
     */
    private function getThresholds(?\DateTimeInterface $now): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        return $item->isHit() && is_array($item->get()) ? $item->get() : $this->refreshThresholds($now);
    }

    /**
     * @param int[]|null $leadIds null for all customers
     *
     * @return array<int, array{orders: int, days_since: int, avg_gap: ?float, monetary: float}>
     */
    private function loadCustomers(?array $leadIds, ?\DateTimeInterface $now): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select('eo.lead_id', 'COUNT(eo.id) AS orders', 'MIN(eo.order_date) AS first_order', 'MAX(eo.order_date) AS last_order', 'SUM(eo.net_total) AS monetary')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id IS NOT NULL')
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->groupBy('eo.lead_id');

        if (null !== $leadIds) {
            $query->andWhere($query->expr()->in('eo.lead_id', ':leadIds'))
                ->setParameter('leadIds', array_map('intval', $leadIds), ArrayParameterType::INTEGER);
        }

        $today     = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->getTimestamp();
        $utc       = new \DateTimeZone('UTC');
        $customers = [];

        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $first  = (new \DateTimeImmutable((string) $row['first_order'], $utc))->getTimestamp();
            $last   = (new \DateTimeImmutable((string) $row['last_order'], $utc))->getTimestamp();
            $orders = (int) $row['orders'];

            $customers[(int) $row['lead_id']] = [
                'orders'     => $orders,
                'days_since' => max(0, (int) floor(($today - $last) / 86400)),
                'avg_gap'    => $orders > 1 && $last > $first ? ($last - $first) / 86400 / ($orders - 1) : null,
                'monetary'   => round((float) $row['monetary'], 4),
            ];
        }

        return $customers;
    }

    private function columnsExist(): bool
    {
        $missing = $this->columnWriter->missingColumns(self::COLUMNS);
        if ([] !== $missing) {
            $this->logger->warning('Ecommerce lifecycle fields are missing; run Install/Upgrade Plugins.', ['columns' => $missing]);
        }

        return [] === $missing;
    }
}
