<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EcommerceEvent>
 */
class EcommerceEventRepository extends CommonRepository
{
    /**
     * The contact's most recent event of one of the given types.
     *
     * @param list<string> $types
     */
    public function findLatestForLead(int $leadId, array $types): ?EcommerceEvent
    {
        return $this->createQueryBuilder('ev')
            ->where('IDENTITY(ev.lead) = :leadId')
            ->andWhere('ev.eventType IN (:types)')
            ->setParameter('leadId', $leadId)
            ->setParameter('types', $types)
            ->orderBy('ev.occurredAt', 'DESC')
            ->addOrderBy('ev.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{total: int, events: list<EcommerceEvent>}
     */
    public function findForLead(int $leadId, int $start, int $limit): array
    {
        $query = $this->createQueryBuilder('ev')
            ->where('IDENTITY(ev.lead) = :leadId')
            ->setParameter('leadId', $leadId);

        $total = (int) (clone $query)->select('COUNT(ev.id)')->getQuery()->getSingleScalarResult();

        return [
            'total'  => $total,
            'events' => $query->orderBy('ev.occurredAt', 'DESC')->addOrderBy('ev.id', 'DESC')
                ->setFirstResult($start)->setMaxResults($limit)->getQuery()->getResult(),
        ];
    }

    /**
     * All of the contact's storefront events, oldest first (retention keeps this short).
     *
     * @return list<EcommerceEvent>
     */
    public function findAllForLead(int $leadId): array
    {
        return $this->createQueryBuilder('ev')
            ->where('IDENTITY(ev.lead) = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy('ev.occurredAt', 'ASC')
            ->addOrderBy('ev.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int number of deleted events
     */
    public function deleteOlderThan(\DateTimeInterface $before, bool $dryRun = false): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'ecommerce_events';
        $date       = \DateTimeImmutable::createFromInterface($before)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if ($dryRun) {
            return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table} WHERE occurred_at < ?", [$date]);
        }

        return (int) $connection->executeStatement("DELETE FROM {$table} WHERE occurred_at < ?", [$date]);
    }

    public function deleteByLeadId(int $leadId): void
    {
        if ($leadId > 0) {
            $this->getEntityManager()->getConnection()->executeStatement(
                'DELETE FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_events WHERE lead_id = ?',
                [$leadId]
            );
        }
    }

    public function updateLead(int $fromLeadId, int $toLeadId): void
    {
        if ($fromLeadId > 0 && $toLeadId > 0 && $fromLeadId !== $toLeadId) {
            $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_events SET lead_id = ? WHERE lead_id = ?',
                [$toLeadId, $fromLeadId]
            );
        }
    }
}
