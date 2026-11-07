<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\DBAL\ArrayParameterType;
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
            ->addSelect('SUM(eo.net_total) AS revenue_total')
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
            'eo.status',
            'eo.refunded_total',
            'eo.net_total',
            'eo.order_currency',
            'eo.order_source',
            'eo.location',
            'eo.order_date',
            'eo.date_added',
            'e.id AS email_id',
            'e.name AS email_name'
        )
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->leftJoin('eo', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = eo.email_id')
            ->where('eo.lead_id = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy('eo.order_date', 'DESC')
            ->addOrderBy('eo.id', 'DESC');

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

        // Net of refunds; cancelled and fully refunded orders do not count.
        $query->select('COUNT(eo.id) AS order_count')
            ->addSelect('COALESCE(SUM('.EcommerceOrder::baseNetSql().'), 0) AS lifetime_value')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('leadId', $leadId)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING);

        $row = $query->executeQuery()->fetchAssociative();

        $lastOrderQuery = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $lastOrderQuery->select(EcommerceOrder::baseNetSql().' AS order_total', 'eo.order_date')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->andWhere($lastOrderQuery->expr()->in('eo.status', ':counted'))
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->orderBy('eo.order_date', 'DESC')
            ->addOrderBy('eo.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('leadId', $leadId);

        $lastOrder = $lastOrderQuery->executeQuery()->fetchAssociative();

        return [
            'order_count'       => (int) ($row['order_count'] ?? 0),
            'lifetime_value'    => number_format((float) ($row['lifetime_value'] ?? 0), 2, '.', ''),
            'last_order_date'   => is_array($lastOrder) && isset($lastOrder['order_date'])
                ? (string) $lastOrder['order_date']
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

    public function deleteByLeadId(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        $connection  = $this->getEntityManager()->getConnection();
        $ordersTable = MAUTIC_TABLE_PREFIX.'ecommerce_orders';
        $itemsTable  = MAUTIC_TABLE_PREFIX.'ecommerce_order_items';

        $orderIds = $connection->createQueryBuilder()
            ->select('eo.id')
            ->from($ordersTable, 'eo')
            ->where('eo.lead_id = :leadId')
            ->setParameter('leadId', $leadId)
            ->executeQuery()
            ->fetchFirstColumn();

        $itemDelete = $connection->createQueryBuilder()->delete($itemsTable);
        if ([] !== $orderIds) {
            $itemDelete
                ->where(
                    $itemDelete->expr()->or(
                        $itemDelete->expr()->in('order_id', ':orderIds'),
                        $itemDelete->expr()->eq('lead_id', ':leadId')
                    )
                )
                ->setParameter('orderIds', array_map('intval', $orderIds), ArrayParameterType::INTEGER)
                ->setParameter('leadId', $leadId)
                ->executeStatement();

            $connection->createQueryBuilder()
                ->delete($ordersTable)
                ->where('lead_id = :leadId')
                ->setParameter('leadId', $leadId)
                ->executeStatement();

            return;
        }

        $itemDelete
            ->where('lead_id = :leadId')
            ->setParameter('leadId', $leadId)
            ->executeStatement();
    }

    /**
     * Unlinks a contact's orders and line items, keeping them for revenue reporting.
     */
    public function anonymizeByLeadId(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        $connection = $this->getEntityManager()->getConnection();

        foreach (['ecommerce_order_items', 'ecommerce_orders'] as $table) {
            $connection->createQueryBuilder()
                ->update(MAUTIC_TABLE_PREFIX.$table)
                ->set('lead_id', 'NULL')
                ->where('lead_id = :leadId')
                ->setParameter('leadId', $leadId)
                ->executeStatement();
        }
    }

    public function updateLead(int $fromLeadId, int $toLeadId): void
    {
        if ($fromLeadId <= 0 || $toLeadId <= 0 || $fromLeadId === $toLeadId) {
            return;
        }

        $connection = $this->getEntityManager()->getConnection();

        $connection->createQueryBuilder()
            ->update(MAUTIC_TABLE_PREFIX.'ecommerce_orders')
            ->set('lead_id', ':toLeadId')
            ->where('lead_id = :fromLeadId')
            ->setParameter('toLeadId', $toLeadId)
            ->setParameter('fromLeadId', $fromLeadId)
            ->executeStatement();

        $connection->createQueryBuilder()
            ->update(MAUTIC_TABLE_PREFIX.'ecommerce_order_items')
            ->set('lead_id', ':toLeadId')
            ->where('lead_id = :fromLeadId')
            ->setParameter('toLeadId', $toLeadId)
            ->setParameter('fromLeadId', $fromLeadId)
            ->executeStatement();
    }

    /**
     * @return array{total: int, orders: list<EcommerceOrder>}
     */
    public function findForApi(?int $leadId, ?string $source, ?\DateTimeInterface $since, int $start, int $limit): array
    {
        $query = $this->createQueryBuilder('eo');

        if (null !== $leadId) {
            $query->andWhere('IDENTITY(eo.lead) = :leadId')->setParameter('leadId', $leadId);
        }

        if (null !== $source) {
            $query->andWhere('eo.orderSource = :source')->setParameter('source', $source);
        }

        if (null !== $since) {
            $query->andWhere('eo.orderDate >= :since')->setParameter('since', $since);
        }

        $total = (int) (clone $query)->select('COUNT(eo.id)')->getQuery()->getSingleScalarResult();

        $orders = $query->orderBy('eo.orderDate', 'DESC')
            ->addOrderBy('eo.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['total' => $total, 'orders' => $orders];
    }

    public function hasCountedOrderSince(int $leadId, \DateTimeInterface $since): bool
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        return (bool) $query->select('1')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where('eo.lead_id = :leadId')
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->andWhere('eo.order_date >= :since')
            ->setParameter('leadId', $leadId)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->setParameter('since', \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
