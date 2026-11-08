<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cross-sell opportunities from co-purchase data, by category.
 *
 * The model counts customers, not orders: for categories A and B, how many customers bought
 * both. A rule A → B holds when at least MIN_SUPPORT customers bought both and buying A makes B
 * more likely than average (lift above 1). Each contact gets the B with the highest confidence
 * (share of A-buyers who also bought B) over the categories A they bought, among categories
 * they have not bought, in ecommerce_cross_sell_category, and that A in ecommerce_cross_sell_from.
 */
class CrossSellCalculator
{
    public const CHUNK_SIZE = 500;

    /** Customers who must have bought both categories before a rule counts. */
    public const MIN_SUPPORT = 5;

    private const CACHE_KEY = 'ecommerce_cross_sell_model';

    private const COLUMNS = [F::CROSS_SELL_CATEGORY, F::CROSS_SELL_FROM];

    public function __construct(
        private Connection $connection,
        private LeadColumnWriter $columnWriter,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Rebuilds the model, then writes every customer's opportunity (and clears stale ones).
     *
     * @return int contacts processed
     */
    public function recalculateAll(): int
    {
        if (!$this->columnsExist()) {
            return 0;
        }

        $categoriesByLead = $this->loadCategories(null);
        $model            = self::buildModel($categoriesByLead);
        $this->saveModel($model);

        $column = $this->connection->quoteIdentifier(F::CROSS_SELL_CATEGORY);
        $stale  = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM '.MAUTIC_TABLE_PREFIX."leads WHERE {$column} IS NOT NULL"));
        $ids    = array_values(array_unique([...array_keys($categoriesByLead), ...$stale]));
        sort($ids);

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $rows = [];
            foreach ($chunk as $leadId) {
                $rows[$leadId] = self::row(self::opportunity($categoriesByLead[$leadId] ?? [], $model));
            }
            $this->columnWriter->write(self::COLUMNS, $rows);
        }

        return count($ids);
    }

    /**
     * After one contact's order changed, with the last model (rebuilt nightly).
     *
     * @param list<int> $leadIds
     */
    public function write(array $leadIds): void
    {
        $leadIds = array_values(array_filter(array_unique(array_map('intval', $leadIds)), static fn (int $id): bool => $id > 0));
        if ([] === $leadIds || !$this->columnsExist()) {
            return;
        }

        $model = $this->loadModel();
        if (null === $model) {
            return;
        }

        $categoriesByLead = $this->loadCategories($leadIds);
        $rows             = [];
        foreach ($leadIds as $leadId) {
            $rows[$leadId] = self::row(self::opportunity($categoriesByLead[$leadId] ?? [], $model));
        }

        $this->columnWriter->write(self::COLUMNS, $rows);
    }

    /**
     * The rules, strongest first, for the command output and the docs' "what did it find".
     *
     * @return list<array{from: string, to: string, customers: int, confidence: float, lift: float}>
     */
    public function rules(): array
    {
        $model = $this->loadModel() ?? ['rules' => []];
        $rules = [];
        foreach ($model['rules'] as $from => $targets) {
            foreach ($targets as $to => $rule) {
                $rules[] = ['from' => (string) $from, 'to' => (string) $to] + $rule;
            }
        }

        usort($rules, static fn (array $a, array $b): int => [$b['confidence'], $b['lift']] <=> [$a['confidence'], $a['lift']]);

        return $rules;
    }

    /**
     * @param array<int, list<string>> $categoriesByLead
     *
     * @return array{customers: int, rules: array<string, array<string, array{customers: int, confidence: float, lift: float}>>}
     */
    public static function buildModel(array $categoriesByLead, int $minSupport = self::MIN_SUPPORT): array
    {
        $customers = count($categoriesByLead);
        $single    = [];
        $pairs     = [];

        foreach ($categoriesByLead as $categories) {
            $categories = array_values(array_unique($categories));
            foreach ($categories as $a) {
                $single[$a] = ($single[$a] ?? 0) + 1;
                foreach ($categories as $b) {
                    if ($a !== $b) {
                        $pairs[$a][$b] = ($pairs[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }

        $rules = [];
        foreach ($pairs as $a => $targets) {
            foreach ($targets as $b => $both) {
                if ($both < $minSupport) {
                    continue;
                }

                $confidence = $both / $single[$a];
                $lift       = $confidence / ($single[$b] / $customers);
                if ($lift > 1.0) {
                    $rules[(string) $a][(string) $b] = ['customers' => $both, 'confidence' => round($confidence, 4), 'lift' => round($lift, 4)];
                }
            }
        }

        return ['customers' => $customers, 'rules' => $rules];
    }

    /**
     * @param list<string>                                                                       $bought
     * @param array{rules: array<string, array<string, array{confidence: float, lift: float}>>} $model
     *
     * @return array{to: string, from: string}|null
     */
    public static function opportunity(array $bought, array $model): ?array
    {
        $best = null;
        foreach (array_unique($bought) as $from) {
            foreach ($model['rules'][$from] ?? [] as $to => $rule) {
                $to = (string) $to;
                if (in_array($to, $bought, true)) {
                    continue;
                }

                $score = [$rule['confidence'], $rule['lift'], $to];
                if (null === $best || $score > $best['score']) {
                    $best = ['to' => $to, 'from' => (string) $from, 'score' => $score];
                }
            }
        }

        return null !== $best ? ['to' => $best['to'], 'from' => $best['from']] : null;
    }

    /**
     * @param array{to: string, from: string}|null $opportunity
     *
     * @return array<string, string|null>
     */
    private static function row(?array $opportunity): array
    {
        return [
            F::CROSS_SELL_CATEGORY => $opportunity['to'] ?? null,
            F::CROSS_SELL_FROM     => $opportunity['from'] ?? null,
        ];
    }

    /**
     * Categories bought in counted orders, net of fully refunded lines.
     *
     * @param list<int>|null $leadIds null for all customers
     *
     * @return array<int, list<string>>
     */
    private function loadCategories(?array $leadIds): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $sql    = "SELECT DISTINCT o.lead_id, i.category FROM {$prefix}ecommerce_orders o
            JOIN {$prefix}ecommerce_order_items i ON i.order_id = o.id
            WHERE o.lead_id IS NOT NULL AND o.status IN (:counted) AND i.category IS NOT NULL AND i.category <> '' AND i.qty > i.refunded_qty";
        $params = ['counted' => EcommerceOrder::COUNTED_STATUSES];
        $types  = ['counted' => ArrayParameterType::STRING];

        if (null !== $leadIds) {
            $sql .= ' AND o.lead_id IN (:leads)';
            $params['leads'] = $leadIds;
            $types['leads']  = ArrayParameterType::INTEGER;
        }

        $categories = [];
        foreach ($this->connection->iterateNumeric($sql, $params, $types) as [$leadId, $category]) {
            $categories[(int) $leadId][] = (string) $category;
        }

        return $categories;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function saveModel(array $model): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($model);
        $item->expiresAfter(3 * 86400);
        $this->cache->save($item);
    }

    /**
     * @return array{customers: int, rules: array<string, array<string, array{customers: int, confidence: float, lift: float}>>}|null
     */
    private function loadModel(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        return $item->isHit() && is_array($item->get()) ? $item->get() : null;
    }

    private function columnsExist(): bool
    {
        $missing = $this->columnWriter->missingColumns(self::COLUMNS);
        if ([] !== $missing) {
            $this->logger->warning('Ecommerce cross-sell fields are missing; run Install/Upgrade Plugins.', ['columns' => $missing]);
        }

        return [] === $missing;
    }
}
