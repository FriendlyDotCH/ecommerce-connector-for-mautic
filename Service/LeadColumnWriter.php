<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Writes computed values into contact field columns with one UPDATE per chunk. Computed
 * fields bypass contact events on purpose, like the commerce fields.
 */
class LeadColumnWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string>                                      $columns
     * @param array<int, array<string, int|float|string|null>> $rows    lead ID => column => value
     */
    public function write(array $columns, array $rows): void
    {
        if ([] === $rows || [] === $columns) {
            return;
        }

        $sets   = [];
        $params = [];
        $types  = [];

        foreach ($columns as $column) {
            $cases = [];
            foreach ($rows as $leadId => $values) {
                $value    = $values[$column] ?? null;
                $cases[]  = 'WHEN ? THEN ?';
                $params[] = $leadId;
                $params[] = $value;
                $types[]  = ParameterType::INTEGER;
                $types[]  = null === $value ? ParameterType::NULL : ParameterType::STRING;
            }

            $sets[] = sprintf('%s = CASE id %s END', $this->connection->quoteIdentifier($column), implode(' ', $cases));
        }

        $params[] = array_keys($rows);
        $types[]  = ArrayParameterType::INTEGER;

        $this->connection->executeStatement(
            sprintf('UPDATE %sleads SET %s WHERE id IN (?)', MAUTIC_TABLE_PREFIX, implode(', ', $sets)),
            $params,
            $types
        );
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string> columns that do not exist on the contacts table
     */
    public function missingColumns(array $columns): array
    {
        $existing = $this->connection->createSchemaManager()->listTableColumns(MAUTIC_TABLE_PREFIX.'leads');

        return array_values(array_filter($columns, static fn (string $column): bool => !isset($existing[strtolower($column)])));
    }
}
