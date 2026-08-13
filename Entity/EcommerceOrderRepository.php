<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EcommerceOrder>
 */
class EcommerceOrderRepository extends CommonRepository
{
    public function orderExists(string $orderId, string $orderSource): bool
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select('1')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.order_id = :orderId')
            ->andWhere('eo.order_source = :orderSource')
            ->setParameter('orderId', $orderId)
            ->setParameter('orderSource', $orderSource)
            ->setMaxResults(1);

        return (bool) $query->executeQuery()->fetchOne();
    }

    /**
     * @return array<string, string>
     */
    public function getRevenueGroupedByCurrencyForEmail(int $emailId): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select('COALESCE(eo.order_currency, :defaultCurrency) AS currency')
            ->addSelect('SUM(eo.order_total) AS revenue_total')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.email_id = :emailId')
            ->groupBy('currency')
            ->setParameter('emailId', $emailId)
            ->setParameter('defaultCurrency', 'USD');

        $results = $query->executeQuery()->fetchAllAssociative();
        $revenue = [];

        foreach ($results as $row) {
            $currency = strtoupper((string) $row['currency']);
            $revenue[$currency] = number_format((float) $row['revenue_total'], 2, '.', '');
        }

        return $revenue;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    public function getOrdersForLeadTimeline(int $leadId, array $options = []): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select(
            'eo.id',
            'eo.order_id',
            'eo.order_total',
            'eo.order_currency',
            'eo.order_source',
            'eo.location',
            'eo.date_added',
            'e.id AS email_id',
            'e.name AS email_name'
        )
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->leftJoin('eo', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = eo.email_id')
            ->where('eo.lead_id = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy('eo.date_added', 'DESC');

        if (!empty($options['limit'])) {
            $query->setMaxResults((int) $options['limit']);
        }

        if (!empty($options['offset'])) {
            $query->setFirstResult((int) $options['offset']);
        }

        $results = $query->executeQuery()->fetchAllAssociative();

        $countQuery = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $countQuery->select('COUNT(eo.id)')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->setParameter('leadId', $leadId);

        return [
            'results' => $results,
            'total'   => (int) $countQuery->executeQuery()->fetchOne(),
        ];
    }

    /**
     * @return array{order_count: int, lifetime_value: string, last_order_date: ?string, last_order_total: string}
     */
    public function getCommerceStatsForLead(int $leadId): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select('COUNT(eo.id) AS order_count')
            ->addSelect('COALESCE(SUM(eo.order_total), 0) AS lifetime_value')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->setParameter('leadId', $leadId);

        $row = $query->executeQuery()->fetchAssociative();

        $lastOrderQuery = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $lastOrderQuery->select('eo.order_total', 'eo.date_added')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->orderBy('eo.date_added', 'DESC')
            ->addOrderBy('eo.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('leadId', $leadId);

        $lastOrder = $lastOrderQuery->executeQuery()->fetchAssociative();

        return [
            'order_count'       => (int) ($row['order_count'] ?? 0),
            'lifetime_value'    => number_format((float) ($row['lifetime_value'] ?? 0), 2, '.', ''),
            'last_order_date'   => is_array($lastOrder) && isset($lastOrder['date_added'])
                ? (string) $lastOrder['date_added']
                : null,
            'last_order_total'  => is_array($lastOrder) && isset($lastOrder['order_total'])
                ? number_format((float) $lastOrder['order_total'], 2, '.', '')
                : '0.00',
        ];
    }

    /**
     * @return int[]
     */
    public function getLeadIdsWithOrders(): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select('DISTINCT eo.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id IS NOT NULL');

        $results = $query->executeQuery()->fetchFirstColumn();

        return array_map('intval', $results);
    }
}
