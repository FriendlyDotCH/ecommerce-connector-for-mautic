<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Platform;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\WooCommerceOrderMapper;
use Symfony\Component\HttpFoundation\Request;

/**
 * WooCommerce's built-in webhooks (WooCommerce > Settings > Advanced > Webhooks) and REST v3
 * objects.
 */
class WooCommercePlatform implements StorePlatformInterface
{
    public const NAME = 'woocommerce';

    public const SIGNATURE_HEADER = 'X-WC-Webhook-Signature';

    public function __construct(
        private Config $config,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * WooCommerce webhooks take any secret: the plugin's webhook secret.
     */
    public function getSigningSecret(Config $config): ?string
    {
        return $config->getWebhookSecret();
    }

    /**
     * Base64 HMAC-SHA256 of the raw body.
     */
    public function isSignatureValid(Request $request, string $secret): bool
    {
        $provided = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        return '' !== $provided
            && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $provided);
    }

    public function getResource(Request $request, ?array $body): ?string
    {
        // A new webhook is pinged with a form body "webhook_id=…".
        if (null === $body) {
            return self::RESOURCE_PING;
        }

        $topic = strtolower((string) $request->headers->get('X-WC-Webhook-Topic', ''));

        return match (true) {
            'product.deleted' === $topic                                  => self::RESOURCE_PRODUCT_DELETE,
            str_starts_with($topic, 'product.')                           => self::RESOURCE_PRODUCT,
            str_starts_with($topic, 'order.'), isset($body['line_items']) => self::RESOURCE_ORDER,
            isset($body['permalink'], $body['type'])                      => self::RESOURCE_PRODUCT,
            default                                                       => null,
        };
    }

    public function mapOrder(array $order): ?array
    {
        return WooCommerceOrderMapper::map($order);
    }

    public function mapProduct(array $product, ?Request $request = null): ?array
    {
        if (!isset($product['id'], $product['name']) || '' === (string) $product['name']) {
            return null;
        }

        $categories = [];
        $main       = null;
        foreach ((array) ($product['categories'] ?? []) as $category) {
            $name = is_array($category) ? trim((string) ($category['name'] ?? '')) : '';
            if ('' === $name) {
                continue;
            }

            $categories[] = $name;
            // WooCommerce's default category says nothing about the product.
            if (null === $main && 'uncategorized' !== ($category['slug'] ?? '')) {
                $main = $name;
            }
        }

        $image = is_array($product['images'][0] ?? null) ? ($product['images'][0]['src'] ?? null) : null;

        return [
            'external_id'    => (string) $product['id'],
            'sku'            => '' !== trim((string) ($product['sku'] ?? '')) ? trim((string) $product['sku']) : null,
            'name'           => mb_substr((string) $product['name'], 0, 191),
            'url'            => self::url($product['permalink'] ?? null),
            'image_url'      => self::url($image),
            'price'          => self::amount($product['price'] ?? null),
            'regular_price'  => self::amount($product['regular_price'] ?? null),
            'sale_price'     => self::amount($product['sale_price'] ?? null),
            'currency'       => $this->config->getDefaultCurrency(),
            'category'       => null !== $main ? mb_substr($main, 0, 191) : (isset($categories[0]) ? mb_substr($categories[0], 0, 191) : null),
            'categories'     => $categories,
            'variation_ids'  => array_map('strval', array_values(array_filter((array) ($product['variations'] ?? []), 'is_numeric'))),
            'stock_status'   => isset($product['stock_status']) ? mb_substr((string) $product['stock_status'], 0, 32) : null,
            'stock_quantity' => isset($product['stock_quantity']) && is_numeric($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
            'status'         => isset($product['status']) ? mb_substr((string) $product['status'], 0, 32) : null,
            'date_modified'  => isset($product['date_modified_gmt']) && is_string($product['date_modified_gmt'])
                ? str_replace('T', ' ', substr($product['date_modified_gmt'], 0, 19))
                : null,
        ];
    }

    public function getDeletedProductId(array $body): ?string
    {
        return isset($body['id']) && is_scalar($body['id']) ? (string) $body['id'] : null;
    }

    private static function amount(mixed $value): ?string
    {
        return is_scalar($value) && '' !== $value && is_numeric($value) ? number_format((float) $value, 4, '.', '') : null;
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('#^https?://#i', $value) ? $value : null;
    }
}
