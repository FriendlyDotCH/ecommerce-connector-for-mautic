<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;

/**
 * Filters of the storefront campaign decisions: minimum value (cart total, or price for a
 * viewed product), categories and products (ID or name), case-insensitive.
 */
final class StorefrontEventMatcher
{
    /**
     * @param array<string, mixed> $config
     */
    public static function matches(EcommerceEvent $event, array $config): bool
    {
        if (isset($config['min_value']) && '' !== $config['min_value'] && null !== $config['min_value']) {
            $value = EcommerceEvent::TYPE_VIEWED_PRODUCT === $event->getEventType() ? $event->getPrice() : $event->getCartValue();
            if (null === $value || (float) $value < (float) $config['min_value']) {
                return false;
            }
        }

        $categories = self::lowerList($config['categories'] ?? null);
        $products   = self::lowerList($config['products'] ?? null);
        if ([] === $categories && [] === $products) {
            return true;
        }

        // The viewed product, or every cart line.
        $lines = EcommerceEvent::TYPE_VIEWED_PRODUCT === $event->getEventType()
            ? [['item_id' => $event->getProductId(), 'item_name' => $event->getProductName(), 'category' => $event->getCategory()]]
            : ($event->getCartItems() ?? []);

        $hasCategory = [] === $categories;
        $hasProduct  = [] === $products;

        foreach ($lines as $line) {
            $hasCategory = $hasCategory || in_array(mb_strtolower((string) ($line['category'] ?? '')), $categories, true);
            $hasProduct  = $hasProduct
                || in_array(mb_strtolower((string) ($line['item_id'] ?? '')), $products, true)
                || in_array(mb_strtolower((string) ($line['item_name'] ?? '')), $products, true);
        }

        return $hasCategory && $hasProduct;
    }

    /**
     * @return list<string>
     */
    private static function lowerList(mixed $list): array
    {
        return array_map(
            static fn (string $value): string => mb_strtolower($value),
            PurchaseCriteria::splitList(is_scalar($list) ? (string) $list : null)
        );
    }
}
