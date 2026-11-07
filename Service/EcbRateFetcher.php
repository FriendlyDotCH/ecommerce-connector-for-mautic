<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stores the European Central Bank's euro reference rates (published on working days).
 */
class EcbRateFetcher
{
    public const URL_90_DAYS = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    public const URL_HISTORY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of stored rates
     */
    public function fetch(bool $fullHistory = false): int
    {
        $xml = $this->httpClient->request('GET', $fullHistory ? self::URL_HISTORY : self::URL_90_DAYS, ['timeout' => 30])->getContent();

        return $this->store(self::parse($xml));
    }

    /**
     * @return list<array{date: string, currency: string, per_eur: float}>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if (false === $document) {
            throw new \RuntimeException('The ECB rates feed could not be read.');
        }

        $rates = [];
        // <Cube><Cube time="2026-11-06"><Cube currency="HUF" rate="395.12"/>...
        foreach ($document->xpath('//*[@time]') ?: [] as $day) {
            $date = (string) $day['time'];
            foreach ($day->children() as $rate) {
                $currency = strtoupper((string) $rate['currency']);
                $perEur   = (float) $rate['rate'];
                if (1 === preg_match('/^[A-Z]{3}$/', $currency) && $perEur > 0 && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $rates[] = ['date' => $date, 'currency' => $currency, 'per_eur' => $perEur];
                }
            }
        }

        return $rates;
    }

    /**
     * @param list<array{date: string, currency: string, per_eur: float}> $rates
     */
    public function store(array $rates): int
    {
        $table = MAUTIC_TABLE_PREFIX.'ecommerce_exchange_rates';

        foreach (array_chunk($rates, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $rate) {
                $values[] = '(?, ?, ?)';
                array_push($params, $rate['date'], $rate['currency'], $rate['per_eur']);
            }

            $this->connection->executeStatement(
                "INSERT INTO {$table} (rate_date, currency, per_eur) VALUES ".implode(', ', $values).' ON DUPLICATE KEY UPDATE per_eur = VALUES(per_eur)',
                $params
            );
        }

        return count($rates);
    }
}
