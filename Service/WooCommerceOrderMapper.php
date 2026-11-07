<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;

/**
 * Turns a native WooCommerce order webhook body (the REST API order object) into this
 * plugin's order fields, so orders and refunds need no store plugin.
 */
final class WooCommerceOrderMapper
{
    public const SOURCE = 'woocommerce';

    /**
     * WooCommerce order statuses. Unpaid ones are not orders yet; they are recorded once the
     * order becomes processing or completed.
     */
    private const STATUSES = [
        'processing' => EcommerceOrder::STATUS_PAID,
        'completed'  => EcommerceOrder::STATUS_PAID,
        'refunded'   => EcommerceOrder::STATUS_REFUNDED,
        'cancelled'  => EcommerceOrder::STATUS_CANCELLED,
    ];

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null this plugin's order input, or null for unpaid or non-order bodies
     */
    public static function map(array $order): ?array
    {
        $status = strtolower((string) ($order['status'] ?? ''));
        if (!isset($order['id'], self::STATUSES[$status])) {
            return null;
        }

        $refunded = 0.0;
        foreach ((array) ($order['refunds'] ?? []) as $refund) {
            // WooCommerce lists refunds with negative totals.
            $refunded += is_array($refund) ? abs((float) ($refund['total'] ?? 0)) : 0.0;
        }

        $items = [];
        foreach ((array) ($order['line_items'] ?? []) as $line) {
            if (!is_array($line) || empty($line['name'])) {
                continue;
            }

            $sku = trim((string) ($line['sku'] ?? ''));
            $qty = max(1, (int) ($line['quantity'] ?? 1));

            $items[] = [
                // SKU is stable across shops and imports; fall back to the product (or variation) ID.
                'item_id'    => '' !== $sku ? $sku : (string) (($line['variation_id'] ?? 0) ?: ($line['product_id'] ?? $line['id'] ?? '')),
                'item_name'  => (string) $line['name'],
                'product_id' => isset($line['product_id']) && (int) $line['product_id'] > 0 ? (string) $line['product_id'] : null,
                'qty'        => $qty,
                // Unit price before discounts and tax, as WooCommerce reports it.
                'unit_price' => isset($line['price']) && is_numeric($line['price'])
                    ? (string) $line['price']
                    : (isset($line['subtotal']) && is_numeric($line['subtotal']) ? (string) ((float) $line['subtotal'] / $qty) : null),
            ];
        }

        return [
            'order_id'       => (string) ($order['number'] ?? $order['id']),
            'order_total'    => (string) ($order['total'] ?? '0'),
            'order_currency' => $order['currency'] ?? null,
            'order_source'   => self::SOURCE,
            'order_date'     => self::gmtDate($order['date_paid_gmt'] ?? null) ?? self::gmtDate($order['date_created_gmt'] ?? null),
            'status'         => self::STATUSES[$status],
            'refunded_total' => $refunded > 0 ? number_format($refunded, 4, '.', '') : null,
            'email'          => is_array($order['billing'] ?? null) ? ($order['billing']['email'] ?? null) : null,
            'location'       => is_array($order['shipping'] ?? null) && !empty($order['shipping']['city']) ? (string) $order['shipping']['city'] : null,
            'items'          => $items,
        ];
    }

    /**
     * WooCommerce's *_gmt fields are UTC without a zone designator.
     */
    private static function gmtDate(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value.'Z' : null;
    }
}
