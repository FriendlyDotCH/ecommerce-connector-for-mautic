<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Revenue figures for dashboard widgets, by order date and net of refunds, counting only
 * paid and partly refunded orders. Currencies are summed as-is.
 */
class EcommerceAnalytics
{
    public function __construct(
        private Connection $connection,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{labels: mixed[], datasets: mixed[]}
     */
    public function getRevenueLineChartData(?string $unit, \DateTime $dateFrom, \DateTime $dateTo, ?string $dateFormat = null): array
    {
        $chart  = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query  = new ChartQuery($this->connection, $dateFrom, $dateTo);
        // Static values only: ChartQuery does not quote IN lists.
        $counted = ['counted' => ['subquery' => "t.status IN ('".implode("','", EcommerceOrder::COUNTED_STATUSES)."')"]];

        $chart->setDataset(
            $this->translator->trans('mautic.plugin.ecommerce.widget.revenue'),
            $query->fetchSumTimeData('ecommerce_orders', 'order_date', $counted, 'net_total')
        );
        $chart->setDataset(
            $this->translator->trans('mautic.plugin.ecommerce.widget.orders'),
            $query->fetchTimeData('ecommerce_orders', 'order_date', $counted)
        );

        return $chart->render();
    }

    /**
     * @return array{revenue: float, orders: int, customers: int, average_order_value: float, repeat_rate: float, average_lifetime_value: float, attributed_revenue: float}
     */
    public function getKeyFigures(\DateTimeInterface $dateFrom, \DateTimeInterface $dateTo): array
    {
        $period = $this->periodQuery($dateFrom, $dateTo)
            ->select('COALESCE(SUM(eo.net_total), 0) AS revenue', 'COUNT(eo.id) AS orders', 'COUNT(DISTINCT eo.lead_id) AS customers')
            ->addSelect('COALESCE(SUM(CASE WHEN eo.email_id IS NOT NULL THEN eo.net_total ELSE 0 END), 0) AS attributed')
            ->executeQuery()
            ->fetchAssociative() ?: [];

        // Lifetime figures for the customers who ordered in the period, up to its end.
        $prefix    = MAUTIC_TABLE_PREFIX;
        $lifetimes = $this->connection->fetchAllAssociative(
            "SELECT eo.lead_id, COUNT(eo.id) AS orders, SUM(eo.net_total) AS value
             FROM {$prefix}ecommerce_orders eo
             WHERE eo.status IN (?) AND eo.order_date <= ? AND eo.lead_id IN (
                 SELECT p.lead_id FROM {$prefix}ecommerce_orders p
                 WHERE p.status IN (?) AND p.order_date BETWEEN ? AND ? AND p.lead_id IS NOT NULL
             )
             GROUP BY eo.lead_id",
            [
                EcommerceOrder::COUNTED_STATUSES, $this->format($dateTo),
                EcommerceOrder::COUNTED_STATUSES, $this->format($dateFrom), $this->format($dateTo),
            ],
            [ArrayParameterType::STRING, null, ArrayParameterType::STRING, null, null]
        );

        $orders      = (int) ($period['orders'] ?? 0);
        $revenue     = (float) ($period['revenue'] ?? 0);
        $customers   = count($lifetimes);
        $repeat      = count(array_filter($lifetimes, static fn (array $row): bool => (int) $row['orders'] > 1));
        $totalValues = array_sum(array_map(static fn (array $row): float => (float) $row['value'], $lifetimes));

        return [
            'revenue'                => round($revenue, 2),
            'orders'                 => $orders,
            'customers'              => $customers,
            'average_order_value'    => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'repeat_rate'            => $customers > 0 ? round(100 * $repeat / $customers, 1) : 0.0,
            'average_lifetime_value' => $customers > 0 ? round($totalValues / $customers, 2) : 0.0,
            'attributed_revenue'     => round((float) ($period['attributed'] ?? 0), 2),
        ];
    }

    /**
     * @return list<array{id: int, name: string, orders: int, revenue: float}>
     */
    public function getRevenueByCampaign(\DateTimeInterface $dateFrom, \DateTimeInterface $dateTo, int $limit): array
    {
        $query = $this->periodQuery($dateFrom, $dateTo)
            ->select('eo.campaign_id AS id', 'c.name', 'COUNT(eo.id) AS orders', 'SUM(eo.net_total) AS revenue')
            ->leftJoin('eo', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = eo.campaign_id')
            ->andWhere('eo.campaign_id IS NOT NULL')
            ->groupBy('eo.campaign_id', 'c.name');

        return $this->ranked($query, $limit);
    }

    /**
     * @return list<array{id: int, name: string, orders: int, revenue: float}>
     */
    public function getRevenueByEmail(\DateTimeInterface $dateFrom, \DateTimeInterface $dateTo, int $limit): array
    {
        $query = $this->periodQuery($dateFrom, $dateTo)
            ->select('eo.email_id AS id', 'e.name', 'COUNT(eo.id) AS orders', 'SUM(eo.net_total) AS revenue')
            ->leftJoin('eo', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = eo.email_id')
            ->andWhere('eo.email_id IS NOT NULL')
            ->groupBy('eo.email_id', 'e.name');

        return $this->ranked($query, $limit);
    }

    /**
     * @return list<array{id: int, name: string, orders: int, revenue: float}>
     */
    private function ranked(QueryBuilder $query, int $limit): array
    {
        $rows = $query->orderBy('revenue', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id'      => (int) $row['id'],
            // A deleted campaign or email keeps its revenue under its old ID.
            'name'    => (string) ($row['name'] ?? '#'.$row['id']),
            'orders'  => (int) $row['orders'],
            'revenue' => round((float) $row['revenue'], 2),
        ], $rows);
    }

    private function periodQuery(\DateTimeInterface $dateFrom, \DateTimeInterface $dateTo): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder();

        return $query->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where($query->expr()->in('eo.status', ':counted'))
            ->andWhere('eo.order_date BETWEEN :dateFrom AND :dateTo')
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->setParameter('dateFrom', $this->format($dateFrom))
            ->setParameter('dateTo', $this->format($dateTo));
    }

    private function format(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
