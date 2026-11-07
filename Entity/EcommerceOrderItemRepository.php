<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EcommerceOrderItem>
 */
class EcommerceOrderItemRepository extends CommonRepository
{
    /**
     * @param int[] $orderIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function getItemsGroupedByOrderIds(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select(
            'ei.order_id',
            'ei.item_id',
            'ei.item_name',
            'ei.qty',
            'ei.refunded_qty',
            'ei.category',
            'ei.unit_price'
        )
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_order_items', 'ei')
            ->where($query->expr()->in('ei.order_id', ':orderIds'))
            ->setParameter('orderIds', $orderIds, ArrayParameterType::INTEGER)
            ->orderBy('ei.id', 'ASC');

        $rows    = $query->executeQuery()->fetchAllAssociative();
        $grouped = [];

        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $grouped[$orderId][] = [
                'item_id'   => (string) $row['item_id'],
                'item_name' => (string) $row['item_name'],
                'qty'       => (int) $row['qty'],
                'refunded_qty' => (int) $row['refunded_qty'],
                'category'  => null !== $row['category'] && '' !== $row['category']
                    ? (string) $row['category']
                    : null,
                'unit_price' => null !== $row['unit_price'] ? number_format((float) $row['unit_price'], 2, '.', '') : null,
            ];
        }

        return $grouped;
    }
}
