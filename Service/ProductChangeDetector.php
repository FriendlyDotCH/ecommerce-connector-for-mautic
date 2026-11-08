<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;

/**
 * Compares the stored product with an update from the shop and names what changed that can
 * trigger a product alert. New products, unpublished products and older updates arriving late
 * change nothing.
 */
final class ProductChangeDetector
{
    public const BACK_IN_STOCK = 'back_in_stock';
    public const PRICE_DROP    = 'price_drop';
    /** Stock went down but not to zero; the low-inventory decision checks its own threshold. */
    public const STOCK_DROP = 'stock_drop';

    /** Shop statuses of products that are not for sale. */
    private const HIDDEN_STATUSES = ['draft', 'pending', 'private', 'trash', 'archived', 'unlisted'];

    /**
     * @param array<string, mixed> $update mapped product (see StorePlatformInterface::mapProduct)
     *
     * @return list<array{type: string, old: string|null, new: string|null}>
     */
    public static function detect(?EcommerceProduct $stored, array $update): array
    {
        if (null === $stored || in_array(strtolower((string) ($update['status'] ?? '')), self::HIDDEN_STATUSES, true)) {
            return [];
        }

        // Webhooks can arrive out of order: an older state must not look like a change.
        $storedModified = $stored->getDateModified();
        $updateModified = isset($update['date_modified']) && is_string($update['date_modified']) ? strtotime($update['date_modified'].' UTC') : false;
        if (null !== $storedModified && false !== $updateModified && $updateModified < $storedModified->getTimestamp()) {
            return [];
        }

        $changes = [];

        $wasOut = 'outofstock' === $stored->getStockStatus() || 0 === $stored->getStockQuantity();
        $isIn   = 'instock' === ($update['stock_status'] ?? null) && 0 !== ($update['stock_quantity'] ?? null);
        if ($wasOut && $isIn) {
            $changes[] = ['type' => self::BACK_IN_STOCK, 'old' => self::quantity($stored->getStockQuantity()), 'new' => self::quantity($update['stock_quantity'] ?? null)];
        }

        $oldPrice = $stored->getPrice();
        $newPrice = isset($update['price']) && is_numeric($update['price']) ? (string) $update['price'] : null;
        if (null !== $oldPrice && null !== $newPrice && (float) $newPrice > 0 && (float) $newPrice < (float) $oldPrice) {
            $changes[] = ['type' => self::PRICE_DROP, 'old' => $oldPrice, 'new' => $newPrice];
        }

        $oldQty = $stored->getStockQuantity();
        $newQty = isset($update['stock_quantity']) && is_int($update['stock_quantity']) ? $update['stock_quantity'] : null;
        if (null !== $oldQty && null !== $newQty && $newQty > 0 && $newQty < $oldQty) {
            $changes[] = ['type' => self::STOCK_DROP, 'old' => self::quantity($oldQty), 'new' => self::quantity($newQty)];
        }

        return $changes;
    }

    private static function quantity(mixed $value): ?string
    {
        return is_int($value) ? (string) $value : null;
    }
}
