<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Platform;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * A shop platform (WooCommerce, Shopify, Magento): how its native webhooks are signed, what
 * they contain, and how its orders and products map to this plugin's fields. Everything after
 * mapping (orders, refunds, catalog, categories, campaigns) is shared.
 */
#[AutoconfigureTag(self::TAG)]
interface StorePlatformInterface
{
    public const TAG = 'ecommerce.store_platform';

    public const RESOURCE_PING           = 'ping';
    public const RESOURCE_ORDER          = 'order';
    public const RESOURCE_PRODUCT        = 'product';
    public const RESOURCE_PRODUCT_DELETE = 'product_delete';

    /**
     * Lowercase name: the webhook path segment, order source and catalog store, e.g. "woocommerce".
     */
    public function getName(): string;

    /**
     * The secret this platform's webhooks are signed with: usually the plugin's webhook secret;
     * Shopify signs with its own key.
     */
    public function getSigningSecret(Config $config): ?string;

    public function isSignatureValid(Request $request, string $secret): bool;

    /**
     * What a verified webhook delivers: one of the RESOURCE_* constants, or null to ignore it.
     *
     * @param array<string, mixed>|null $body decoded JSON body, null if not JSON
     */
    public function getResource(Request $request, ?array $body): ?string;

    /**
     * Maps an order to OrderPayloadParser input, with "email" for the contact.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null null when it is not an order yet (for example unpaid)
     */
    public function mapOrder(array $order): ?array;

    /**
     * Maps a product to a catalog row: external_id, sku, name, url, image_url, price,
     * regular_price, sale_price, currency, category, categories, variation_ids, stock_status,
     * stock_quantity, status, date_modified.
     *
     * @param array<string, mixed> $product
     * @param Request|null         $request the webhook, for platforms that send details in headers
     *
     * @return array<string, mixed>|null
     */
    public function mapProduct(array $product, ?Request $request = null): ?array;

    /**
     * @param array<string, mixed> $body
     */
    public function getDeletedProductId(array $body): ?string;
}
