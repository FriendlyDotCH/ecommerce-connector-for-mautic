<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Platform;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Platform\ShopifyPlatform;
use MauticPlugin\EcommerceConnectorBundle\Platform\StorePlatformInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ShopifyPlatformTest extends TestCase
{
    private Config&MockObject $config;

    private ShopifyPlatform $platform;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDefaultCurrency')->willReturn('CHF');

        $this->platform = new ShopifyPlatform($this->config);
    }

    public function testMapsAProductWithVariants(): void
    {
        $product = $this->platform->mapProduct(WooCommercePlatformTest::fixture('shopify-product.json'), $this->request('products/update', 'friendly-candles.myshopify.com'));

        $this->assertSame('8312345678001', $product['external_id']);
        $this->assertNull($product['sku'], 'Several variants: the SKUs are on the variants.');
        $this->assertSame('Lavender Candle', $product['name']);
        $this->assertSame('https://friendly-candles.myshopify.com/products/lavender-candle', $product['url']);
        $this->assertSame('https://cdn.shopify.com/s/files/1/0000/0001/products/lavender.jpg', $product['image_url']);
        $this->assertSame('12.5000', $product['price']);
        $this->assertSame('15.0000', $product['regular_price']);
        $this->assertSame('12.5000', $product['sale_price']);
        $this->assertSame('CHF', $product['currency']);
        $this->assertSame('Candles', $product['category']);
        $this->assertSame(['Candles'], $product['categories'], 'Product type and taxonomy name are the same here.');
        $this->assertSame(['45012345678001', '45012345678003'], $product['variation_ids']);
        $this->assertSame('instock', $product['stock_status']);
        $this->assertSame(7, $product['stock_quantity']);
        $this->assertSame('active', $product['status']);
        $this->assertSame('2026-11-02 09:14:51', $product['date_modified'], 'In UTC.');
    }

    public function testProductDetailsWithoutTypeStockOrValidDomain(): void
    {
        $raw                 = WooCommercePlatformTest::fixture('shopify-product.json');
        $raw['product_type'] = '';
        $raw['variants']     = [$raw['variants'][1]];

        $product = $this->platform->mapProduct($raw, $this->request('products/update', 'evil.example.com'));

        $this->assertSame('Candles', $product['category'], 'Falls back to the standard product category.');
        $this->assertSame('CND-LAV-400', $product['sku'], 'One variant: its SKU.');
        $this->assertSame('outofstock', $product['stock_status']);
        $this->assertSame(0, $product['stock_quantity']);
        $this->assertNull($product['url'], 'Only myshopify.com domains make links.');
        $this->assertNull($product['sale_price']);

        $this->assertNull($this->platform->mapProduct(['id' => 1, 'title' => '']));
    }

    public function testResources(): void
    {
        $this->assertSame(StorePlatformInterface::RESOURCE_ORDER, $this->platform->getResource($this->request('orders/create'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_ORDER, $this->platform->getResource($this->request('orders/updated'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_ORDER, $this->platform->getResource($this->request('orders/cancelled'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_PRODUCT, $this->platform->getResource($this->request('products/create'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_PRODUCT_DELETE, $this->platform->getResource($this->request('products/delete'), ['id' => 1]));
        $this->assertNull($this->platform->getResource($this->request('refunds/create'), ['id' => 1]), 'Refunds arrive with orders/updated.');
        $this->assertNull($this->platform->getResource($this->request('customers/create'), ['id' => 1]));
        $this->assertNull($this->platform->getResource($this->request('orders/create'), null));
    }

    public function testSignature(): void
    {
        $request = $this->request('orders/create');
        $request->headers->set('X-Shopify-Hmac-Sha256', base64_encode(hash_hmac('sha256', $request->getContent(), 'shpss_key', true)));

        $this->assertTrue($this->platform->isSignatureValid($request, 'shpss_key'));
        $this->assertFalse($this->platform->isSignatureValid($request, 'other'));
    }

    public function testSigningSecretFallsBackToThePluginSecret(): void
    {
        $this->config->method('getWebhookSecret')->willReturn('plugin-secret');
        $this->config->method('getShopifyWebhookSecret')->willReturnOnConsecutiveCalls('shopify-key', null);

        $this->assertSame('shopify-key', $this->platform->getSigningSecret($this->config));
        $this->assertSame('plugin-secret', $this->platform->getSigningSecret($this->config));
    }

    private function request(string $topic, string $domain = 'friendly-candles.myshopify.com'): Request
    {
        return new Request([], [], [], [], [], ['HTTP_X_SHOPIFY_TOPIC' => $topic, 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $domain], '{"id":1}');
    }
}
