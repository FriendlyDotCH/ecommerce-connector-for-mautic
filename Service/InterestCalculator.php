<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use Psr\Log\LoggerInterface;

/**
 * The product a contact showed most interest in lately without buying it: storefront views (one
 * point per day viewed) and carts (two points per cart), over the last DAYS days. Written to
 * Interest product / Interest category; empty below MIN_SCORE or once bought. Daily for everyone,
 * and for one contact after their storefront events and orders.
 */
class InterestCalculator
{
    public const DAYS = 30;

    /** Two views on different days, or one cart. */
    public const MIN_SCORE = 2;

    public const CART_POINTS = 2;

    public const CHUNK_SIZE = 500;

    private const COLUMNS = [F::INTEREST_PRODUCT, F::INTEREST_CATEGORY];

    private const CART_TYPES = [EcommerceEvent::TYPE_ADDED_TO_CART, EcommerceEvent::TYPE_STARTED_CHECKOUT];

    public function __construct(
        private Connection $connection,
        private LeadColumnWriter $columnWriter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int contacts processed
     */
    public function recalculateAll(?\DateTimeInterface $now = null): int
    {
        if (!$this->columnsExist()) {
            return 0;
        }

        $since  = $this->since($now);
        $column = $this->connection->quoteIdentifier(F::INTEREST_PRODUCT);
        $ids    = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT lead_id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_events WHERE occurred_at >= ?
             UNION SELECT id FROM '.MAUTIC_TABLE_PREFIX."leads WHERE {$column} IS NOT NULL",
            [$since]
        ));
        sort($ids);

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->writeChunk($chunk, $since);
        }

        return count($ids);
    }

    /**
     * @param list<int> $leadIds
     */
    public function write(array $leadIds, ?\DateTimeInterface $now = null): void
    {
        $leadIds = array_values(array_filter(array_unique(array_map('intval', $leadIds)), static fn (int $id): bool => $id > 0));
        if ([] === $leadIds || !$this->columnsExist()) {
            return;
        }

        foreach (array_chunk($leadIds, self::CHUNK_SIZE) as $chunk) {
            $this->writeChunk($chunk, $this->since($now));
        }
    }

    /**
     * The strongest signal for a product not bought: most points, then most recent, then name.
     *
     * @param list<array{type: string, day: string, cart: ?string, ids: list<string>, name: string, category: ?string}> $signals
     * @param array<string, true>                                                                                         $bought lower-cased product IDs, SKUs and names
     *
     * @return array{product: string, category: ?string, score: int}|null
     */
    public static function pick(array $signals, array $bought): ?array
    {
        $products = [];
        foreach ($signals as $signal) {
            foreach ([...$signal['ids'], $signal['name']] as $id) {
                if (isset($bought[mb_strtolower($id)])) {
                    continue 2;
                }
            }

            $key = mb_strtolower($signal['name']);
            $products[$key] ??= ['product' => $signal['name'], 'category' => null, 'views' => [], 'carts' => [], 'last' => ''];
            $products[$key]['category'] ??= $signal['category'];
            $products[$key]['last'] = max($products[$key]['last'], $signal['day']);
            if (in_array($signal['type'], self::CART_TYPES, true)) {
                $products[$key]['carts'][$signal['cart'] ?? $signal['day']] = true;
            } else {
                $products[$key]['views'][$signal['day']] = true;
            }
        }

        $best = null;
        foreach ($products as $product) {
            $score = count($product['views']) + self::CART_POINTS * count($product['carts']);
            if ($score < self::MIN_SCORE) {
                continue;
            }

            $rank = [$score, $product['last'], $product['product']];
            if (null === $best || $rank > $best['rank']) {
                $best = ['product' => $product['product'], 'category' => $product['category'], 'score' => $score, 'rank' => $rank];
            }
        }

        return null !== $best ? ['product' => $best['product'], 'category' => $best['category'], 'score' => $best['score']] : null;
    }

    /**
     * @param list<int> $leadIds
     */
    private function writeChunk(array $leadIds, string $since): void
    {
        $signals = $this->loadSignals($leadIds, $since);
        $bought  = $this->loadBought(array_keys($signals));

        $rows = [];
        foreach ($leadIds as $leadId) {
            $interest      = isset($signals[$leadId]) ? self::pick($signals[$leadId], $bought[$leadId] ?? []) : null;
            $rows[$leadId] = [
                F::INTEREST_PRODUCT  => null !== $interest ? mb_substr($interest['product'], 0, 191) : null,
                F::INTEREST_CATEGORY => null !== $interest && null !== $interest['category'] ? mb_substr($interest['category'], 0, 191) : null,
            ];
        }

        $this->columnWriter->write(self::COLUMNS, $rows);
    }

    /**
     * @param list<int> $leadIds
     *
     * @return array<int, list<array{type: string, day: string, cart: ?string, ids: list<string>, name: string, category: ?string}>>
     */
    private function loadSignals(array $leadIds, string $since): array
    {
        $rows = $this->connection->iterateAssociative(
            'SELECT lead_id, event_type, product_id, product_name, category, cart_id, cart_items, DATE(occurred_at) AS day
             FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_events WHERE lead_id IN (?) AND occurred_at >= ?',
            [$leadIds, $since],
            [ArrayParameterType::INTEGER, null]
        );

        $signals = [];
        foreach ($rows as $row) {
            $leadId = (int) $row['lead_id'];
            $type   = (string) $row['event_type'];
            $day    = (string) $row['day'];

            if (EcommerceEvent::TYPE_VIEWED_PRODUCT === $type) {
                $name = trim((string) ($row['product_name'] ?? $row['product_id'] ?? ''));
                if ('' !== $name) {
                    $signals[$leadId][] = ['type' => $type, 'day' => $day, 'cart' => null, 'ids' => array_values(array_filter([(string) $row['product_id']])), 'name' => $name, 'category' => $row['category'] ?: null];
                }
                continue;
            }

            $items = json_decode((string) $row['cart_items'], true);
            foreach (is_array($items) ? $items : [] as $item) {
                $name = is_array($item) ? trim((string) ($item['item_name'] ?? '')) : '';
                if ('' !== $name) {
                    $signals[$leadId][] = ['type' => $type, 'day' => $day, 'cart' => $row['cart_id'] ? (string) $row['cart_id'] : null, 'ids' => array_values(array_filter([(string) ($item['item_id'] ?? '')])), 'name' => $name, 'category' => ($item['category'] ?? null) ?: null];
                }
            }
        }

        return $signals;
    }

    /**
     * Everything each contact bought: product IDs, SKUs and names, lower-cased.
     *
     * @param list<int> $leadIds
     *
     * @return array<int, array<string, true>>
     */
    private function loadBought(array $leadIds): array
    {
        if ([] === $leadIds) {
            return [];
        }

        $rows = $this->connection->iterateNumeric(
            'SELECT DISTINCT o.lead_id, i.product_id, i.item_id, i.item_name FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_orders o
             JOIN '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items i ON i.order_id = o.id
             WHERE o.lead_id IN (?) AND o.status IN (?)',
            [$leadIds, EcommerceOrder::COUNTED_STATUSES],
            [ArrayParameterType::INTEGER, ArrayParameterType::STRING]
        );

        $bought = [];
        foreach ($rows as [$leadId, $productId, $itemId, $name]) {
            foreach ([$productId, $itemId, $name] as $id) {
                if (null !== $id && '' !== (string) $id) {
                    $bought[(int) $leadId][mb_strtolower((string) $id)] = true;
                }
            }
        }

        return $bought;
    }

    private function since(?\DateTimeInterface $now): string
    {
        return \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('-%d days', self::DAYS))
            ->format('Y-m-d H:i:s');
    }

    private function columnsExist(): bool
    {
        $missing = $this->columnWriter->missingColumns(self::COLUMNS);
        if ([] !== $missing) {
            $this->logger->warning('Ecommerce interest fields are missing; run Install/Upgrade Plugins.', ['columns' => $missing]);
        }

        return [] === $missing;
    }
}
