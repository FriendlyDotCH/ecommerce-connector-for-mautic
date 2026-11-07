<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;

/**
 * Aggregates spend, quantity or order count per contact. Used by the purchase history
 * campaign condition and by behaviours.
 *
 * Everything is net of refunds, and cancelled or fully refunded orders do not count. Spend
 * narrowed to categories or products is (qty - refunded qty) x unit price of the matching
 * items, so items sent without a unit price count as zero. Currencies are summed as-is.
 */
class PurchaseHistoryQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function aggregateForLead(PurchaseCriteria $criteria, int $leadId, ?\DateTimeInterface $now = null): float
    {
        return $this->aggregateForLeads($criteria, [$leadId], $now)[$leadId] ?? 0.0;
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, float> lead ID => value, 0.0 for contacts without matching orders
     */
    public function aggregateForLeads(PurchaseCriteria $criteria, array $leadIds, ?\DateTimeInterface $now = null): array
    {
        $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), static fn (int $id): bool => $id > 0)));
        if ([] === $leadIds) {
            return [];
        }

        $query = $this->connection->createQueryBuilder();
        $query->select('eo.lead_id', $this->valueExpression($criteria).' AS value')
            ->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', 'eo')
            ->where($query->expr()->in('eo.lead_id', ':leadIds'))
            ->andWhere($query->expr()->in('eo.status', ':counted'))
            ->setParameter('leadIds', $leadIds, ArrayParameterType::INTEGER)
            ->setParameter('counted', EcommerceOrder::COUNTED_STATUSES, ArrayParameterType::STRING)
            ->groupBy('eo.lead_id');

        if ($criteria->needsItems()) {
            $query->innerJoin('eo', MAUTIC_TABLE_PREFIX.'ecommerce_order_items', 'ei', 'ei.order_id = eo.id');
        }

        if ($criteria->days > 0) {
            $since = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())
                ->setTimezone(new \DateTimeZone('UTC'))
                ->modify(sprintf('-%d days', $criteria->days));

            $query->andWhere('eo.order_date >= :since')
                ->setParameter('since', $since->format('Y-m-d H:i:s'));
        }

        // Comparisons follow the column collation, which is case-insensitive.
        match ($criteria->scope) {
            PurchaseCriteria::SCOPE_CATEGORY => $query->andWhere($query->expr()->in('ei.category', ':values')),
            PurchaseCriteria::SCOPE_PRODUCT  => $query->andWhere($query->expr()->or(
                $query->expr()->in('ei.item_id', ':values'),
                $query->expr()->in('ei.item_name', ':values'),
            )),
            PurchaseCriteria::SCOPE_SOURCE   => $query->andWhere($query->expr()->in('eo.order_source', ':values')),
            default                          => null,
        };

        if ([] !== $criteria->values) {
            $query->setParameter('values', $criteria->values, ArrayParameterType::STRING);
        }

        $values = array_fill_keys($leadIds, 0.0);
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $values[(int) $row['lead_id']] = round((float) $row['value'], 4);
        }

        return $values;
    }

    private function valueExpression(PurchaseCriteria $criteria): string
    {
        $itemScope = in_array($criteria->scope, [PurchaseCriteria::SCOPE_CATEGORY, PurchaseCriteria::SCOPE_PRODUCT], true);

        return match ($criteria->metric) {
            PurchaseCriteria::METRIC_SPEND => $itemScope
                ? 'COALESCE(SUM((ei.qty - ei.refunded_qty) * COALESCE(ei.unit_price, 0)), 0)'
                : 'COALESCE(SUM(eo.net_total), 0)',
            PurchaseCriteria::METRIC_QTY    => 'COALESCE(SUM(ei.qty - ei.refunded_qty), 0)',
            PurchaseCriteria::METRIC_ORDERS => 'COUNT(DISTINCT eo.id)',
        };
    }
}
