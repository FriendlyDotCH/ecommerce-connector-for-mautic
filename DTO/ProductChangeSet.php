<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

/**
 * The changes of one type that one contact may be alerted about in one run: passed to the
 * product alert decision once, so a sale on several products is one alert email.
 */
final class ProductChangeSet
{
    /**
     * @param list<ProductChange> $changes all of the same type
     * @param string              $alertedAt UTC, shared by the alerts recorded for this set
     */
    public function __construct(
        public readonly string $type,
        public readonly array $changes,
        public readonly string $alertedAt,
    ) {
    }

    public function anyMatched(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->matched) {
                return true;
            }
        }

        return false;
    }
}
