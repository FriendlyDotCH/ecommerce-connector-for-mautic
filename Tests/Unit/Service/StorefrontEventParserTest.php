<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StorefrontEventParserTest extends TestCase
{
    private StorefrontEventParser $parser;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getEventRetentionDays')->willReturn(90);
        $config->method('getMaxOrderTotal')->willReturn(1000000.0);

        $this->parser = new StorefrontEventParser($config, $this->createMock(LoggerInterface::class));
    }

    public function testNestedViewedProduct(): void
    {
        $payload = $this->parser->parse([
            'event'   => 'Product-Viewed',
            'product' => ['id' => 'CND-1', 'name' => 'Lavender Candle', 'category' => 'Candles', 'price' => '19.9', 'url' => 'https://shop.example/candle', 'image_url' => 'javascript:alert(1)'],
            'currency' => 'huf',
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('viewed_product', $payload->eventType);
        $this->assertSame('CND-1', $payload->productId);
        $this->assertSame('19.9000', $payload->price);
        $this->assertSame('HUF', $payload->currency);
        $this->assertSame('https://shop.example/candle', $payload->productUrl);
        $this->assertNull($payload->imageUrl, 'Only http(s) URLs are kept.');
        $this->assertSame('web', $payload->source);
    }

    public function testCartEventComputesTotalFromItems(): void
    {
        $payload = $this->parser->parse([
            'event_type' => 'begin_checkout',
            'occurred_at' => (new \DateTimeImmutable('-5 minutes'))->format(\DATE_ATOM),
            'cart'       => [
                'id'    => 'cart-9',
                'url'   => 'https://shop.example/cart?restore=9',
                'items' => [
                    ['item_id' => 'A', 'name' => 'Candle', 'qty' => 2, 'price' => '10'],
                    ['item_id' => 'B', 'item_name' => 'Soap', 'qty' => 1, 'unit_price' => '5.5'],
                    ['item_id' => 'C', 'qty' => 1],
                ],
            ],
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('started_checkout', $payload->eventType);
        $this->assertSame('cart-9', $payload->cartId);
        $this->assertSame('25.5000', $payload->cartValue);
        $this->assertCount(2, $payload->cartItems);
        $this->assertSame('https://shop.example/cart?restore=9', $payload->cartUrl);
    }

    public function testRejectsUnknownTypeMissingDataAndOldDates(): void
    {
        $this->assertNull($this->parser->parse(['event' => 'wishlist', 'product_id' => 'x']));
        $this->assertNull($this->parser->parse(['event' => 'viewed_product']));
        $this->assertNull($this->parser->parse(['event' => 'added_to_cart']));
        $this->assertNull($this->parser->parse(['event' => 'viewed_product', 'product_id' => 'x', 'occurred_at' => '-100 days']));
        $this->assertNotNull($this->parser->parse(['event' => 'add_to_cart', 'cart_total' => '12']));
    }
}
