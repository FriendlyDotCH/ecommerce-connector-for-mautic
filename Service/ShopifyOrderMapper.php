<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;

/**
 * Turns a Shopify order webhook body (the Admin REST order object, as sent for orders/create,
 * orders/updated, orders/paid and orders/cancelled) into this plugin's order fields.
 *
 * Amounts are in the currency the customer paid in (presentment currency), like WooCommerce
 * orders; Mautic converts to the base currency itself. Refunds arrive inside the order
 * (orders/updated), with their transactions and refunded line quantities.
 */
final class ShopifyOrderMapper
{
    public const SOURCE = 'shopify';

    /**
     * financial_status => this plugin's status. Unpaid ones (pending, authorized, partially_paid)
     * are not orders yet; they are recorded once paid.
     */
    private const STATUSES = [
        'paid'               => EcommerceOrder::STATUS_PAID,
        'partially_refunded' => EcommerceOrder::STATUS_PAID,
        'refunded'           => EcommerceOrder::STATUS_REFUNDED,
        'voided'             => EcommerceOrder::STATUS_CANCELLED,
    ];

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null this plugin's order input, or null for unpaid, test or non-order bodies
     */
    public static function map(array $order): ?array
    {
        if (!isset($order['id'], $order['line_items']) || !empty($order['test'])) {
            return null;
        }

        $status = self::STATUSES[strtolower((string) ($order['financial_status'] ?? ''))] ?? null;
        if (!empty($order['cancelled_at']) && EcommerceOrder::STATUS_REFUNDED !== $status) {
            // Cancelled, paid or not: recorded as cancelled, which counts nothing (like WooCommerce).
            // A full refund stays "refunded", since that money went back.
            $status = EcommerceOrder::STATUS_CANCELLED;
        }

        if (null === $status) {
            return null;
        }

        $currency     = self::presentmentCurrency($order);
        $taxIncluded  = !empty($order['taxes_included']);
        $refundedQty  = self::refundedQuantities($order);
        $items        = [];

        foreach ((array) $order['line_items'] as $line) {
            if (!is_array($line) || '' === trim((string) ($line['title'] ?? $line['name'] ?? ''))) {
                continue;
            }

            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $sku = trim((string) ($line['sku'] ?? ''));

            $items[] = [
                // SKU first, matching the catalog; else the variant, else the product.
                'item_id'      => '' !== $sku ? $sku : (string) (($line['variant_id'] ?? null) ?: ($line['product_id'] ?? $line['id'])),
                'item_name'    => (string) ($line['name'] ?? $line['title']),
                'product_id'   => !empty($line['product_id']) ? (string) $line['product_id'] : null,
                'qty'          => $qty,
                'unit_price'   => self::unitPrice($line, $qty, $taxIncluded),
                'refunded_qty' => min($qty, $refundedQty[(string) ($line['id'] ?? '')] ?? 0),
            ];
        }

        $refunded = self::refundedTotal($order, $currency);
        $shipping = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];

        return [
            // The number shown to customers, without Shopify's "#" prefix.
            'order_id'       => ltrim((string) ($order['name'] ?? $order['order_number'] ?? $order['id']), '#'),
            'order_total'    => self::money($order, 'total_price', $currency) ?? '0',
            'order_currency' => $currency,
            'order_source'   => self::SOURCE,
            'order_date'     => self::date($order['processed_at'] ?? null) ?? self::date($order['created_at'] ?? null),
            'status'         => $status,
            'refunded_total' => $refunded > 0 ? number_format($refunded, 4, '.', '') : null,
            'email'          => self::email($order),
            'location'       => !empty($shipping['city']) ? (string) $shipping['city'] : null,
            'items'          => $items,
            'coupons'        => array_column(array_filter((array) ($order['discount_codes'] ?? []), 'is_array'), 'code'),
        ] + self::clickthrough($order);
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function presentmentCurrency(array $order): ?string
    {
        $currency = $order['presentment_currency'] ?? $order['currency'] ?? null;

        return is_string($currency) && 1 === preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : null;
    }

    /**
     * An amount in the presentment currency: from "<field>_set" when present, else the plain field
     * (shop currency, the same unless the shop sells in several currencies).
     *
     * @param array<string, mixed> $object
     */
    private static function money(array $object, string $field, ?string $currency): ?string
    {
        $set = $object[$field.'_set']['presentment_money'] ?? null;
        if (is_array($set) && isset($set['amount']) && is_numeric($set['amount'])
            && (null === $currency || strtoupper((string) ($set['currency_code'] ?? $currency)) === $currency)) {
            return (string) $set['amount'];
        }

        return isset($object[$field]) && is_numeric($object[$field]) ? (string) $object[$field] : null;
    }

    /**
     * Per unit before discounts, including tax, like WooCommerce orders. Shopify's line price is
     * before discounts; it includes tax only when the shop's prices do (taxes_included).
     *
     * @param array<string, mixed> $line
     */
    private static function unitPrice(array $line, int $qty, bool $taxIncluded): ?string
    {
        $price = self::money($line, 'price', null);
        if (null === $price) {
            return null;
        }

        $unit = (float) $price;
        if (!$taxIncluded) {
            $tax = 0.0;
            foreach ((array) ($line['tax_lines'] ?? []) as $taxLine) {
                $tax += is_array($taxLine) ? (float) (self::money($taxLine, 'price', null) ?? 0) : 0.0;
            }
            $unit += $tax / $qty;
        }

        return (string) round($unit, 4);
    }

    /**
     * Money actually returned: successful refund transactions. Falls back to the refunded lines
     * (subtotal plus tax) when the webhook carries no transactions.
     *
     * @param array<string, mixed> $order
     */
    private static function refundedTotal(array $order, ?string $currency): float
    {
        $total = 0.0;

        foreach ((array) ($order['refunds'] ?? []) as $refund) {
            if (!is_array($refund)) {
                continue;
            }

            $fromTransactions = 0.0;
            $hasTransactions  = false;
            foreach ((array) ($refund['transactions'] ?? []) as $transaction) {
                if (is_array($transaction) && 'refund' === ($transaction['kind'] ?? null) && 'success' === ($transaction['status'] ?? null)) {
                    $hasTransactions = true;
                    $fromTransactions += abs((float) ($transaction['amount'] ?? 0));
                }
            }

            if ($hasTransactions) {
                $total += $fromTransactions;
                continue;
            }

            foreach ((array) ($refund['refund_line_items'] ?? []) as $line) {
                if (is_array($line)) {
                    $total += (float) (self::money($line, 'subtotal', $currency) ?? 0) + (float) (self::money($line, 'total_tax', $currency) ?? 0);
                }
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, int> line item ID => refunded quantity
     */
    private static function refundedQuantities(array $order): array
    {
        $quantities = [];

        foreach ((array) ($order['refunds'] ?? []) as $refund) {
            foreach (is_array($refund) ? (array) ($refund['refund_line_items'] ?? []) : [] as $line) {
                if (is_array($line) && isset($line['line_item_id'])) {
                    $id              = (string) $line['line_item_id'];
                    $quantities[$id] = ($quantities[$id] ?? 0) + max(0, (int) ($line['quantity'] ?? 0));
                }
            }
        }

        return $quantities;
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function email(array $order): ?string
    {
        foreach ([$order['email'] ?? null, $order['contact_email'] ?? null, is_array($order['customer'] ?? null) ? ($order['customer']['email'] ?? null) : null] as $email) {
            if (is_string($email) && '' !== trim($email)) {
                return trim($email);
            }
        }

        return null;
    }

    /**
     * The Mautic email link the shopper arrived from: Shopify keeps the first page's URL, with
     * its query, in landing_site. Mautic verifies it by the tracking hash in it.
     *
     * @param array<string, mixed> $order
     *
     * @return array{ct?: string}
     */
    private static function clickthrough(array $order): array
    {
        $landing = $order['landing_site'] ?? null;
        if (!is_string($landing) || !str_contains($landing, 'ct=')) {
            return [];
        }

        parse_str((string) parse_url($landing, PHP_URL_QUERY), $query);
        $ct = $query['ct'] ?? null;

        return is_string($ct) && '' !== $ct && strlen($ct) <= 2048 ? ['ct' => $ct] : [];
    }

    /**
     * Shopify dates are ISO 8601 with offset.
     */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
