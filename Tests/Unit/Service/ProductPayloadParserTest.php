<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductPayloadParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductPayloadParserTest extends TestCase
{
    private function parser(array $allowedSources = []): ProductPayloadParser
    {
        $config = $this->createMock(Config::class);
        $config->method('getAllowedOrderSources')->willReturn($allowedSources);
        $config->method('getDefaultCurrency')->willReturn('HUF');

        return new ProductPayloadParser($config, $this->createMock(LoggerInterface::class));
    }

    public function testParsesAFullProduct(): void
    {
        $parsed = $this->parser()->parse([
            'store'          => 'WooCommerce',
            'id'             => 160,
            'sku'            => 'DIF-REED',
            'name'           => 'Reed Diffuser',
            'url'            => 'https://shop.example/product/reed-diffuser/',
            'image_url'      => 'ftp://nope',
            'price'          => '12990',
            'sale_price'     => '',
            'categories'     => ['Diffusers', ['name' => 'Gifts'], 'Diffusers', ''],
            'variation_ids'  => [164, '165'],
            'stock_status'   => 'InStock',
            'stock_quantity' => '7',
            'updated_at'     => '2026-11-02T10:14:51+01:00',
            'created_at'     => 1790000000,
        ]);

        $this->assertSame('woocommerce', $parsed['store']);
        $product = $parsed['product'];
        $this->assertSame('160', $product['external_id']);
        $this->assertSame(['Diffusers', 'Gifts'], $product['categories']);
        $this->assertSame('Diffusers', $product['category']);
        $this->assertSame(['164', '165'], $product['variation_ids']);
        $this->assertSame('12990.0000', $product['price']);
        $this->assertNull($product['sale_price']);
        $this->assertNull($product['image_url']);
        $this->assertSame('HUF', $product['currency']);
        $this->assertSame('instock', $product['stock_status']);
        $this->assertSame(7, $product['stock_quantity']);
        $this->assertSame('2026-11-02 09:14:51', $product['date_modified']);
        $this->assertSame(gmdate('Y-m-d H:i:s', 1790000000), $product['date_created']);
    }

    public function testCategoriesAsTextAndExplicitMainCategory(): void
    {
        $product = $this->parser()->parse(['store' => 'pos', 'id' => 'X', 'name' => 'Gift box', 'categories' => 'Gifts, Candles', 'category' => 'Candles'])['product'];

        $this->assertSame(['Gifts', 'Candles'], $product['categories']);
        $this->assertSame('Candles', $product['category']);
    }

    public function testRejectsMissingFieldsAndDisallowedStores(): void
    {
        $this->assertNull($this->parser()->parse(['id' => 1, 'name' => 'No store']));
        $this->assertNull($this->parser()->parse(['store' => 'web', 'name' => 'No id']));
        $this->assertNull($this->parser()->parse(['store' => 'web', 'id' => 1]));
        $this->assertNull($this->parser(['woocommerce'])->parse(['store' => 'shopify', 'id' => 1, 'name' => 'X']));
        $this->assertNotNull($this->parser(['WooCommerce'])->parse(['store' => 'woocommerce', 'id' => 1, 'name' => 'X']));
    }
}
