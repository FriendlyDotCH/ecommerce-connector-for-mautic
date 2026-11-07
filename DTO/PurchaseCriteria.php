<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

/**
 * What to measure in a contact's purchase history: a metric, optionally narrowed to
 * categories, products or order sources, over a rolling window of days.
 */
final readonly class PurchaseCriteria
{
    public const METRIC_SPEND  = 'spend';
    public const METRIC_QTY    = 'qty';
    public const METRIC_ORDERS = 'orders';

    public const METRICS = [self::METRIC_SPEND, self::METRIC_QTY, self::METRIC_ORDERS];

    public const SCOPE_ANY      = 'any';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT  = 'product';
    public const SCOPE_SOURCE   = 'source';

    public const SCOPES = [self::SCOPE_ANY, self::SCOPE_CATEGORY, self::SCOPE_PRODUCT, self::SCOPE_SOURCE];

    public string $scope;

    /**
     * @var list<string>
     */
    public array $values;

    /**
     * @param list<string> $values categories, product IDs or names, or sources; empty means any
     * @param int          $days   rolling window in days; 0 means all time
     */
    public function __construct(
        public string $metric,
        string $scope = self::SCOPE_ANY,
        array $values = [],
        public int $days = 0,
    ) {
        if (!in_array($metric, self::METRICS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown purchase metric "%s".', $metric));
        }

        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown purchase scope "%s".', $scope));
        }

        if ($days < 0) {
            throw new \InvalidArgumentException('The purchase window cannot be negative.');
        }

        $values = array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $values),
            static fn (string $value): bool => '' !== $value
        )));

        // A scope without values matches everything.
        $this->scope  = [] === $values ? self::SCOPE_ANY : $scope;
        $this->values = self::SCOPE_ANY === $this->scope ? [] : $values;
    }

    /**
     * @return list<string>
     */
    public static function splitList(?string $list): array
    {
        if (null === $list || '' === trim($list)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $list)),
            static fn (string $value): bool => '' !== $value
        ));
    }

    public function needsItems(): bool
    {
        return self::METRIC_QTY === $this->metric
            || self::SCOPE_CATEGORY === $this->scope
            || self::SCOPE_PRODUCT === $this->scope;
    }
}
