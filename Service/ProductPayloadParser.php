<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;

/**
 * Reads a product in the plugin's standard format, sent by store connectors to
 * POST /ecommerce/product. Produces the same catalog row as the platform adapters.
 */
class ProductPayloadParser
{
    public const TEXT_MAX = 191;
    public const URL_MAX  = 2048;

    private const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    private ?string $lastError = null;

    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{store: string, product: array<string, mixed>}|null
     */
    public function parse(array $input, string $context = 'request'): ?array
    {
        $this->lastError = null;

        $store = $this->store($input['store'] ?? null);
        if (null === $store) {
            return $this->reject($context, 'store must match the order_source the store uses for orders, and be allowed.');
        }

        $externalId = $this->text($input['id'] ?? $input['product_id'] ?? null);
        $name       = $this->text($input['name'] ?? null);
        if (null === $externalId || null === $name) {
            return $this->reject($context, 'id and name are required.');
        }

        $categories = $input['categories'] ?? [];
        if (is_string($categories)) {
            $categories = explode(',', $categories);
        }
        $categories = array_values(array_unique(array_filter(
            array_map(fn (mixed $category): ?string => $this->text(is_array($category) ? ($category['name'] ?? null) : $category), is_array($categories) ? $categories : []),
            static fn (?string $category): bool => null !== $category
        )));

        $stockStatus = strtolower((string) ($input['stock_status'] ?? ''));
        $variations  = is_array($input['variation_ids'] ?? null) ? $input['variation_ids'] : [];

        return [
            'store'   => $store,
            'product' => [
                'external_id'    => $externalId,
                'sku'            => $this->text($input['sku'] ?? null),
                'name'           => $name,
                'url'            => $this->url($input['url'] ?? null),
                'image_url'      => $this->url($input['image_url'] ?? null),
                'price'          => $this->amount($input['price'] ?? null),
                'regular_price'  => $this->amount($input['regular_price'] ?? null),
                'sale_price'     => $this->amount($input['sale_price'] ?? null),
                'currency'       => $this->currency($input['currency'] ?? null),
                'category'       => $this->text($input['category'] ?? null) ?? ($categories[0] ?? null),
                'categories'     => $categories,
                'variation_ids'  => array_values(array_filter(array_map(fn (mixed $id): ?string => $this->text($id), $variations))),
                'stock_status'   => in_array($stockStatus, self::STOCK_STATUSES, true) ? $stockStatus : null,
                'stock_quantity' => isset($input['stock_quantity']) && is_numeric($input['stock_quantity']) ? (int) $input['stock_quantity'] : null,
                'status'         => $this->text($input['status'] ?? null, 32),
                'date_modified'  => $this->date($input['updated_at'] ?? null),
                'date_created'   => $this->date($input['created_at'] ?? null),
            ],
        ];
    }

    /**
     * Why the last parse() returned null.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The store name, matching how orders name their source; null when missing or not allowed.
     */
    public function store(mixed $value): ?string
    {
        $store = $this->text($value, 64);
        if (null === $store) {
            return null;
        }

        $store   = strtolower($store);
        $allowed = array_map('strtolower', $this->config->getAllowedOrderSources());

        return [] === $allowed || in_array($store, $allowed, true) ? $store : null;
    }

    private function amount(mixed $value): ?string
    {
        return is_scalar($value) && '' !== $value && is_numeric($value) && (float) $value >= 0
            ? number_format((float) $value, 4, '.', '')
            : null;
    }

    private function currency(mixed $value): ?string
    {
        $currency = is_string($value) ? strtoupper(trim($value)) : '';

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : $this->config->getDefaultCurrency();
    }

    private function date(mixed $value): ?string
    {
        if (!is_scalar($value) || '' === $value) {
            return null;
        }

        try {
            $value = (string) $value;
            $date  = ctype_digit($value) ? new \DateTimeImmutable('@'.$value) : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function text(mixed $value, int $max = self::TEXT_MAX): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? mb_substr($value, 0, $max) : null;
    }

    private function url(mixed $value): ?string
    {
        $url = $this->text($value, self::URL_MAX);

        return null !== $url && 1 === preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function reject(string $context, string $reason): null
    {
        $this->lastError = $reason;
        $this->logger->warning(sprintf('Ecommerce product rejected (%s): %s', $context, $reason));

        return null;
    }
}
