<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;

/**
 * Base currency units per unit of an order currency on a date: 1 for the base currency, else
 * a manual rate from the settings, else the ECB reference rates stored for the closest date
 * on or before it (or the earliest one after, for orders older than the stored history).
 */
class ExchangeRateProvider
{
    /**
     * @var array<string, ?float>
     */
    private array $memo = [];

    public function __construct(
        private Connection $connection,
        private Config $config,
    ) {
    }

    public function getRate(?string $currency, \DateTimeInterface $date): ?string
    {
        $base     = $this->config->getBaseCurrency();
        $currency = strtoupper($currency ?? $this->config->getDefaultCurrency());

        if ($currency === $base) {
            return '1';
        }

        $manual = $this->config->getManualExchangeRates();
        if (isset($manual[$currency])) {
            return (string) $manual[$currency];
        }

        $day     = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $perEurB = $this->perEur($base, $day);
        $perEurC = $this->perEur($currency, $day);

        return null !== $perEurB && null !== $perEurC && $perEurC > 0 ? (string) ($perEurB / $perEurC) : null;
    }

    private function perEur(string $currency, string $day): ?float
    {
        if ('EUR' === $currency) {
            return 1.0;
        }

        $key = $currency.'|'.$day;
        if (!array_key_exists($key, $this->memo)) {
            $table = MAUTIC_TABLE_PREFIX.'ecommerce_exchange_rates';
            $rate  = $this->connection->fetchOne(
                "SELECT per_eur FROM {$table} WHERE currency = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1",
                [$currency, $day]
            );

            if (false === $rate) {
                $rate = $this->connection->fetchOne(
                    "SELECT per_eur FROM {$table} WHERE currency = ? ORDER BY rate_date ASC LIMIT 1",
                    [$currency]
                );
            }

            $this->memo[$key] = false !== $rate ? (float) $rate : null;
        }

        return $this->memo[$key];
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
