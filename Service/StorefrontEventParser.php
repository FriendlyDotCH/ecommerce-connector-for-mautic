<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\DTO\StorefrontEventPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;

/**
 * Reads a storefront event from a webhook, API or tracking payload. Accepts nested "product"
 * and "cart" objects or flat fields, and the event names stores commonly use.
 */
class StorefrontEventParser
{
    public const TEXT_MAX      = 191;
    public const URL_MAX       = 2048;
    public const MAX_CART_ITEMS = 100;

    private const TYPE_ALIASES = [
        'viewed_product'    => EcommerceEvent::TYPE_VIEWED_PRODUCT,
        'product_viewed'    => EcommerceEvent::TYPE_VIEWED_PRODUCT,
        'view_product'      => EcommerceEvent::TYPE_VIEWED_PRODUCT,
        'view_item'         => EcommerceEvent::TYPE_VIEWED_PRODUCT,
        'added_to_cart'     => EcommerceEvent::TYPE_ADDED_TO_CART,
        'add_to_cart'       => EcommerceEvent::TYPE_ADDED_TO_CART,
        'cart_updated'      => EcommerceEvent::TYPE_ADDED_TO_CART,
        'started_checkout'  => EcommerceEvent::TYPE_STARTED_CHECKOUT,
        'checkout_started'  => EcommerceEvent::TYPE_STARTED_CHECKOUT,
        'begin_checkout'    => EcommerceEvent::TYPE_STARTED_CHECKOUT,
        'initiate_checkout' => EcommerceEvent::TYPE_STARTED_CHECKOUT,
    ];

    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function parse(array $input, string $context = 'request'): ?StorefrontEventPayload
    {
        $rawType = $input['event'] ?? $input['event_type'] ?? $input['ecommerce_event'] ?? null;
        $type    = is_scalar($rawType) ? (self::TYPE_ALIASES[str_replace(['-', ' '], '_', strtolower(trim((string) $rawType)))] ?? null) : null;
        if (null === $type) {
            return $this->reject($context, 'event must be viewed_product, added_to_cart or started_checkout.');
        }

        $occurredAt = $this->parseDate($input['occurred_at'] ?? null);
        if (null === $occurredAt) {
            return $this->reject($context, 'occurred_at must be an ISO 8601 date or Unix timestamp within the retention period.');
        }

        $product = is_array($input['product'] ?? null) ? $input['product'] : $input;
        $cart    = is_array($input['cart'] ?? null) ? $input['cart'] : $input;

        $currency = $this->text($input['currency'] ?? $cart['currency'] ?? $product['currency'] ?? null, 3);
        $currency = null !== $currency && 1 === preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : null;

        $items = $this->parseItems($cart['items'] ?? null);

        $payload = new StorefrontEventPayload(
            $type,
            $occurredAt,
            $this->text($input['source'] ?? null, 64) ?? 'web',
            $this->text($product['product_id'] ?? $product['id'] ?? null),
            $this->text($product['product_name'] ?? $product['name'] ?? null),
            $this->text($product['category'] ?? null),
            $this->amount($product['price'] ?? null),
            $currency,
            $this->url($product['product_url'] ?? $product['url'] ?? null),
            $this->url($product['image_url'] ?? null),
            $this->text($cart['cart_id'] ?? ($cart !== $input ? ($cart['id'] ?? null) : null)),
            $this->amount($cart['cart_total'] ?? $cart['total'] ?? null) ?? $this->itemsTotal($items),
            $this->url($cart['cart_url'] ?? ($cart !== $input ? ($cart['url'] ?? null) : null)),
            [] !== $items ? $items : null,
        );

        if (EcommerceEvent::TYPE_VIEWED_PRODUCT === $type && null === $payload->productId && null === $payload->productName) {
            return $this->reject($context, 'viewed_product needs a product id or name.');
        }

        if (EcommerceEvent::TYPE_VIEWED_PRODUCT !== $type && null === $payload->cartItems && null === $payload->cartValue) {
            return $this->reject($context, sprintf('%s needs cart items or a cart total.', $type));
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseItems(mixed $items): array
    {
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        if (!is_array($items)) {
            return [];
        }

        $parsed = [];
        foreach (array_slice($items, 0, self::MAX_CART_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->text($item['item_name'] ?? $item['name'] ?? null);
            $qty  = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;
            if (null === $name || $qty < 1) {
                continue;
            }

            $parsed[] = [
                'item_id'    => $this->text($item['item_id'] ?? $item['id'] ?? null),
                'item_name'  => $name,
                'qty'        => $qty,
                'unit_price' => $this->amount($item['unit_price'] ?? $item['price'] ?? null),
                'category'   => $this->text($item['category'] ?? null),
                'url'        => $this->url($item['url'] ?? null),
                'image_url'  => $this->url($item['image_url'] ?? null),
            ];
        }

        return $parsed;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function itemsTotal(array $items): ?string
    {
        $total = 0.0;
        foreach ($items as $item) {
            if (null === $item['unit_price']) {
                return null;
            }
            $total += $item['qty'] * (float) $item['unit_price'];
        }

        return [] !== $items ? number_format($total, 4, '.', '') : null;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        if (!is_scalar($value)) {
            return null;
        }

        try {
            $value = trim((string) $value);
            $date  = ctype_digit($value) ? new \DateTimeImmutable('@'.$value) : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        $oldest = new \DateTimeImmutable(sprintf('-%d days', $this->config->getEventRetentionDays()));
        if ($date > new \DateTimeImmutable('+1 day') || $date < $oldest) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function amount(mixed $value): ?string
    {
        if (!is_scalar($value) || '' === $value || !is_numeric($value) || (float) $value < 0 || (float) $value > $this->config->getMaxOrderTotal()) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function text(mixed $value, int $max = self::TEXT_MAX): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Only http(s) URLs: they end up in emails.
     */
    private function url(mixed $value): ?string
    {
        $url = $this->text($value, self::URL_MAX);

        return null !== $url && 1 === preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function reject(string $context, string $reason): null
    {
        $this->logger->warning(sprintf('Ecommerce storefront event rejected (%s): %s', $context, $reason));

        return null;
    }
}
