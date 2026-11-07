<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;
use Psr\Log\LoggerInterface;

/**
 * Writes behaviour values straight into the contacts table in bulk, by contact-ID chunks.
 * Like the commerce fields these are computed values, so no contact events are fired.
 */
class BehaviourCalculator
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private EcommerceBehaviourRepository $behaviourRepository,
        private PurchaseHistoryQuery $purchaseHistoryQuery,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Recalculates every published behaviour for one contact, for example after a new order.
     */
    public function recalculateForLead(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        foreach ($this->behaviourRepository->findPublished() as $behaviour) {
            $this->writeValues($behaviour, [$leadId]);
        }
    }

    /**
     * Recalculates a behaviour for every contact that has orders or a non-zero value.
     *
     * @return int number of contacts processed
     */
    public function recalculate(EcommerceBehaviour $behaviour, ?\DateTimeInterface $now = null): int
    {
        if (!$this->columnExists($behaviour)) {
            $this->logger->warning('Ecommerce behaviour field column is missing; skipping.', ['alias' => $behaviour->getFieldAlias()]);

            return 0;
        }

        $processed = 0;
        foreach (array_chunk($this->getCandidateLeadIds($behaviour), self::CHUNK_SIZE) as $chunk) {
            $this->writeValues($behaviour, $chunk, $now);
            $processed += count($chunk);
        }

        $behaviour->setLastCalculated(new \DateTime());
        $this->entityManager->persist($behaviour);
        $this->entityManager->flush();

        return $processed;
    }

    /**
     * @param int[] $leadIds
     */
    private function writeValues(EcommerceBehaviour $behaviour, array $leadIds, ?\DateTimeInterface $now = null): void
    {
        $alias = $behaviour->getFieldAlias();
        if (null === $alias) {
            return;
        }

        $values = $this->purchaseHistoryQuery->aggregateForLeads($behaviour->getCriteria(), $leadIds, $now);
        if ([] === $values) {
            return;
        }

        $cases  = [];
        $params = [];
        foreach ($values as $leadId => $value) {
            $cases[]  = 'WHEN ? THEN ?';
            $params[] = $leadId;
            $params[] = $value;
        }

        $column = $this->connection->quoteIdentifier($alias);
        $sql    = sprintf(
            'UPDATE %sleads SET %s = CASE id %s END WHERE id IN (?)',
            MAUTIC_TABLE_PREFIX,
            $column,
            implode(' ', $cases)
        );

        $this->connection->executeStatement(
            $sql,
            [...$params, array_keys($values)],
            [...array_fill(0, count($params), \Doctrine\DBAL\ParameterType::STRING), ArrayParameterType::INTEGER]
        );
    }

    /**
     * Contacts with orders, plus contacts whose value must fall back to zero.
     *
     * @return int[]
     */
    private function getCandidateLeadIds(EcommerceBehaviour $behaviour): array
    {
        $column = $this->connection->quoteIdentifier((string) $behaviour->getFieldAlias());
        $prefix = MAUTIC_TABLE_PREFIX;

        $ids = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT lead_id FROM {$prefix}ecommerce_orders WHERE lead_id IS NOT NULL
             UNION
             SELECT id FROM {$prefix}leads WHERE {$column} <> 0"
        );

        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    private function columnExists(EcommerceBehaviour $behaviour): bool
    {
        $alias = $behaviour->getFieldAlias();
        if (null === $alias) {
            return false;
        }

        $columns = $this->connection->createSchemaManager()->listTableColumns(MAUTIC_TABLE_PREFIX.'leads');

        return isset($columns[strtolower($alias)]);
    }
}
