<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderPayloadParserTest extends TestCase
{
    private Config&MockObject $config;

    private OrderPayloadParser $parser;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getMaxOrderTotal')->willReturn(1000000.0);
        $this->config->method('getDefaultCurrency')->willReturn('USD');
        $this->config->method('getAllowedOrderSources')->willReturn([]);

        $this->parser = new OrderPayloadParser(
            $this->config,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testParseReturnsPayloadForValidInput(): void
    {
        $payload = $this->parser->parse([
            'order_id'       => 'order-123',
            'order_total'    => '49.99',
            'order_source'   => 'woocommerce',
            'order_currency' => 'eur',
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('order-123', $payload->orderId);
        $this->assertSame('49.9900', $payload->orderTotal);
        $this->assertSame('woocommerce', $payload->orderSource);
        $this->assertSame('EUR', $payload->orderCurrency);
    }

    public function testParseAllowsZeroTotalsAndMissingTotal(): void
    {
        $zeroPayload = $this->parser->parse([
            'order_id'    => 'order-123',
            'order_total' => '0',
        ]);

        $this->assertNotNull($zeroPayload);
        $this->assertSame('0.0000', $zeroPayload->orderTotal);

        $missingTotal = $this->parser->parse([
            'order_id' => 'order-456',
        ]);

        $this->assertNotNull($missingTotal);
        $this->assertSame('0.0000', $missingTotal->orderTotal);
    }

    public function testParseRejectsNegativeTotals(): void
    {
        $this->assertNull($this->parser->parse([
            'order_id'    => 'order-123',
            'order_total' => '-10',
        ]));
    }

    public function testParseRejectsDisallowedSource(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMaxOrderTotal')->willReturn(1000000.0);
        $config->method('getDefaultCurrency')->willReturn('USD');
        $config->method('getAllowedOrderSources')->willReturn(['web']);

        $parser = new OrderPayloadParser(
            $config,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertNull($parser->parse([
            'order_id'     => 'order-123',
            'order_total'  => '10',
            'order_source' => 'shopify',
        ]));
    }

    public function testParseAllowsOrderSourceCaseInsensitively(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMaxOrderTotal')->willReturn(1000000.0);
        $config->method('getDefaultCurrency')->willReturn('USD');
        $config->method('getAllowedOrderSources')->willReturn(['WooCommerce']);

        $parser = new OrderPayloadParser(
            $config,
            $this->createMock(LoggerInterface::class)
        );

        $payload = $parser->parse([
            'order_id'     => 'order-123',
            'order_total'  => '10',
            'order_source' => 'woocommerce',
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('woocommerce', $payload->orderSource);
    }

    public function testParseIncludesLineItemsAndLocation(): void
    {
        $payload = $this->parser->parse([
            'order_id' => 'order-789',
            'location' => 'hixson',
            'items'    => [
                [
                    'item_id'   => '1722',
                    'item_name' => 'Smart Water 1 Liter',
                    'qty'       => 1,
                    'category'  => 'Beverages',
                ],
            ],
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('hixson', $payload->location);
        $this->assertCount(1, $payload->items);
        $this->assertSame('1722', $payload->items[0]->itemId);
        $this->assertSame('Beverages', $payload->items[0]->category);
    }

    public function testParseReadsUnitPriceFromUnitPriceOrPrice(): void
    {
        $payload = $this->parser->parse([
            'order_id' => 'order-790',
            'items'    => [
                ['item_id' => 'a', 'item_name' => 'Candle', 'qty' => 2, 'unit_price' => '12.5'],
                ['item_id' => 'b', 'item_name' => 'Diffuser', 'qty' => 1, 'price' => 30],
                ['item_id' => 'c', 'item_name' => 'Sample', 'qty' => 1],
                ['item_id' => 'd', 'item_name' => 'Broken', 'qty' => 1, 'unit_price' => '-3'],
            ],
        ]);

        $this->assertNotNull($payload);
        $this->assertCount(4, $payload->items);
        $this->assertSame('12.5000', $payload->items[0]->unitPrice);
        $this->assertSame('30.0000', $payload->items[1]->unitPrice);
        $this->assertNull($payload->items[2]->unitPrice);
        $this->assertNull($payload->items[3]->unitPrice);
    }

    public function testParseOrderDateFromIsoStringAndTimestamp(): void
    {
        $iso = $this->parser->parse([
            'order_id'   => 'order-791',
            'order_date' => '2026-03-01T10:15:00+02:00',
        ]);

        $this->assertNotNull($iso);
        $this->assertNotNull($iso->orderDate);
        $this->assertSame('2026-03-01 08:15:00', $iso->orderDate->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $iso->orderDate->getTimezone()->getName());

        $timestamp = $this->parser->parse([
            'order_id'   => 'order-792',
            'order_date' => '1772360100',
        ]);

        $this->assertNotNull($timestamp);
        $this->assertSame('2026-03-01 10:15:00', $timestamp->orderDate?->format('Y-m-d H:i:s'));
    }

    public function testParseWithoutOrderDateLeavesItUnset(): void
    {
        $payload = $this->parser->parse(['order_id' => 'order-793']);

        $this->assertNotNull($payload);
        $this->assertNull($payload->orderDate);
    }

    public function testParseRejectsInvalidOrFutureOrderDate(): void
    {
        $this->assertNull($this->parser->parse([
            'order_id'   => 'order-794',
            'order_date' => 'not a date',
        ]));

        $this->assertNull($this->parser->parse([
            'order_id'   => 'order-795',
            'order_date' => (new \DateTimeImmutable('+3 days'))->format(\DATE_ATOM),
        ]));
    }
}
