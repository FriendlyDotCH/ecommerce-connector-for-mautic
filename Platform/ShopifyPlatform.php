<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Platform;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\ShopifyOrderMapper;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shopify's webhooks (Settings > Notifications > Webhooks, or an app's subscriptions), Admin REST
 * order and product objects, at /ecommerce/webhook/shopify.
 *
 * Shopify signs with its own key, not one you choose: the key shown under the webhook list for
 * admin-created webhooks, or the app's client secret. It goes into "Shopify webhook signing
 * secret"; without one, the plugin's webhook secret is tried.
 */
class ShopifyPlatform implements StorePlatformInterface
{
    public const NAME = 'shopify';

    public const SIGNATURE_HEADER = 'X-Shopify-Hmac-Sha256';
    public const TOPIC_HEADER     = 'X-Shopify-Topic';
    public const DOMAIN_HEADER    = 'X-Shopify-Shop-Domain';

    private const ORDER_TOPICS = ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'orders/fulfilled', 'orders/partially_fulfilled'];

    public function __construct(
        private Config $config,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSigningSecret(Config $config): ?string
    {
        return $config->getShopifyWebhookSecret() ?? $config->getWebhookSecret();
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
        $topic = strtolower((string) $request->headers->get(self::TOPIC_HEADER, ''));

        return match (true) {
            null === $body                         => null,
            'products/delete' === $topic           => self::RESOURCE_PRODUCT_DELETE,
            in_array($topic, ['products/create', 'products/update'], true) => self::RESOURCE_PRODUCT,
            in_array($topic, self::ORDER_TOPICS, true) => self::RESOURCE_ORDER,
            default                                => null,
        };
    }

    public function mapOrder(array $order): ?array
    {
        return ShopifyOrderMapper::map($order);
    }

    /**
     * Shopify sends no storefront URL; product links use the shop's myshopify.com domain from the
     * webhook header, which redirects to the shop's own domain.
     */
    public function mapProduct(array $product, ?Request $request = null): ?array
    {
        $name = trim((string) ($product['title'] ?? ''));
        if (!isset($product['id']) || '' === $name) {
            return null;
        }

        $variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));
        $first    = $variants[0] ?? [];
        $price    = self::amount($first['price'] ?? null);
        $compare  = self::amount($first['compare_at_price'] ?? null);
        $onSale   = null !== $price && null !== $compare && (float) $compare > (float) $price;

        // Category: the merchant's product type, else Shopify's standard product category.
        $type       = trim((string) ($product['product_type'] ?? ''));
        $taxonomy   = is_array($product['category'] ?? null) ? trim((string) ($product['category']['name'] ?? '')) : '';
        $categories = array_values(array_unique(array_filter([$type, $taxonomy], static fn (string $c): bool => '' !== $c)));

        $image = is_array($product['image'] ?? null) ? ($product['image']['src'] ?? null) : null;
        if (null === $image && is_array($product['images'][0] ?? null)) {
            $image = $product['images'][0]['src'] ?? null;
        }

        $domain = $request?->headers->get(self::DOMAIN_HEADER);
        $handle = (string) ($product['handle'] ?? '');
        $url    = is_string($domain) && 1 === preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) && 1 === preg_match('/^[a-z0-9-]+$/', $handle)
            ? 'https://'.$domain.'/products/'.$handle
            : null;

        [$stockStatus, $stockQuantity] = self::stock($variants);

        return [
            'external_id'    => (string) $product['id'],
            // Single-variant products carry their SKU on the variant.
            'sku'            => 1 === count($variants) && '' !== trim((string) ($first['sku'] ?? '')) ? trim((string) $first['sku']) : null,
            'name'           => mb_substr($name, 0, 191),
            'url'            => $url,
            'image_url'      => is_string($image) && 1 === preg_match('#^https?://#i', $image) ? $image : null,
            'price'          => $price,
            'regular_price'  => $onSale ? $compare : $price,
            'sale_price'     => $onSale ? $price : null,
            'currency'       => $this->config->getDefaultCurrency(),
            'category'       => isset($categories[0]) ? mb_substr($categories[0], 0, 191) : null,
            'categories'     => $categories,
            'variation_ids'  => array_map(static fn (array $variant): string => (string) ($variant['id'] ?? ''), $variants),
            'stock_status'   => $stockStatus,
            'stock_quantity' => $stockQuantity,
            'status'         => isset($product['status']) ? mb_substr((string) $product['status'], 0, 32) : null,
            'date_modified'  => self::utc($product['updated_at'] ?? null),
            'date_created'   => self::utc($product['created_at'] ?? null),
        ];
    }

    public function getDeletedProductId(array $body): ?string
    {
        return isset($body['id']) && is_scalar($body['id']) ? (string) $body['id'] : null;
    }

    /**
     * In stock when any variant can be sold: stock left, not tracked, or overselling allowed.
     * The quantity is the sum of tracked variants.
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function stock(array $variants): array
    {
        if ([] === $variants) {
            return [null, null];
        }

        $available = false;
        $tracked   = false;
        $quantity  = 0;

        foreach ($variants as $variant) {
            $isTracked = 'shopify' === ($variant['inventory_management'] ?? null);
            $qty       = (int) ($variant['inventory_quantity'] ?? 0);

            $tracked   = $tracked || $isTracked;
            $quantity += $isTracked ? max(0, $qty) : 0;
            $available = $available || !$isTracked || $qty > 0 || 'continue' === ($variant['inventory_policy'] ?? null);
        }

        return [$available ? 'instock' : 'outofstock', $tracked ? $quantity : null];
    }

    private static function amount(mixed $value): ?string
    {
        return is_scalar($value) && '' !== $value && is_numeric($value) ? number_format((float) $value, 4, '.', '') : null;
    }

    private static function utc(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
