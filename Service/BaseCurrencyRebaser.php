<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Sets each stored order's exchange rate and base net total, for orders recorded before a
 * rate was known, or for all orders after the base currency changes.
 */
class BaseCurrencyRebaser
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
        private ExchangeRateProvider $rateProvider,
    ) {
    }

    /**
     * @param bool $all false: only orders without a rate; true: every order
     *
     * @return array{converted: int, without_rate: int}
     */
    public function rebase(bool $all = false): array
    {
        $this->rateProvider->reset();

        $table     = MAUTIC_TABLE_PREFIX.'ecommerce_orders';
        $lastId    = 0;
        $converted = 0;
        $missing   = 0;

        do {
            $orders = $this->connection->fetchAllAssociative(
                "SELECT id, order_currency, order_date, net_total FROM {$table}
                 WHERE id > ?".($all ? '' : ' AND exchange_rate IS NULL').'
                 ORDER BY id ASC LIMIT '.self::CHUNK_SIZE,
                [$lastId]
            );

            $rateCases = [];
            $baseCases = [];
            $params    = [];
            $types     = [];
            $baseParam = [];
            $baseTypes = [];

            foreach ($orders as $order) {
                $lastId = (int) $order['id'];
                $rate   = $this->rateProvider->getRate($order['order_currency'], new \DateTimeImmutable((string) $order['order_date'], new \DateTimeZone('UTC')));
                $base   = null !== $rate ? number_format((float) $order['net_total'] * (float) $rate, 4, '.', '') : null;

                null !== $rate ? ++$converted : ++$missing;

                $rateCases[] = 'WHEN ? THEN ?';
                array_push($params, $lastId, $rate);
                array_push($types, ParameterType::INTEGER, null === $rate ? ParameterType::NULL : ParameterType::STRING);

                $baseCases[] = 'WHEN ? THEN ?';
                array_push($baseParam, $lastId, $base);
                array_push($baseTypes, ParameterType::INTEGER, null === $base ? ParameterType::NULL : ParameterType::STRING);
            }

            if ([] !== $orders) {
                $this->connection->executeStatement(
                    "UPDATE {$table} SET exchange_rate = CASE id ".implode(' ', $rateCases).' END, base_net_total = CASE id '.implode(' ', $baseCases).' END WHERE id IN (?)',
                    [...$params, ...$baseParam, array_map(static fn (array $order): int => (int) $order['id'], $orders)],
                    [...$types, ...$baseTypes, ArrayParameterType::INTEGER]
                );
            }
        } while (count($orders) === self::CHUNK_SIZE);

        return ['converted' => $converted, 'without_rate' => $missing];
    }
}
