<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\ShopifyOrderMapper;
use MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Platform\WooCommercePlatformTest;
use PHPUnit\Framework\TestCase;

class ShopifyOrderMapperTest extends TestCase
{
    public function testMapsAPaidOrderInThePresentmentCurrency(): void
    {
        $mapped = ShopifyOrderMapper::map(self::order());

        $this->assertSame('1042', $mapped['order_id'], 'The order name without "#".');
        $this->assertSame('49.80', $mapped['order_total'], 'What the customer paid, in CHF.');
        $this->assertSame('CHF', $mapped['order_currency']);
        $this->assertSame('shopify', $mapped['order_source']);
        $this->assertSame('2026-11-07T14:02:10+01:00', $mapped['order_date']);
        $this->assertSame('paid', $mapped['status']);
        $this->assertNull($mapped['refunded_total']);
        $this->assertSame('jane@example.com', $mapped['email']);
        $this->assertSame('Basel', $mapped['location']);

        $this->assertSame(
            [
                ['item_id' => 'CND-LAV-200', 'item_name' => 'Lavender Candle - 200g', 'product_id' => '8312345678001', 'qty' => 2, 'unit_price' => '12.5', 'refunded_qty' => 0],
                // No SKU: the variant ID.
                ['item_id' => '45012345678002', 'item_name' => 'Reed Diffuser', 'product_id' => '8312345678002', 'qty' => 1, 'unit_price' => '20', 'refunded_qty' => 0],
            ],
            $mapped['items']
        );
    }

    public function testEmailLinkFromTheLandingPage(): void
    {
        $mapped = ShopifyOrderMapper::map(self::order());

        $this->assertStringEndsWith('fQ==', $mapped['ct'], 'URL-decoded.');

        $order                 = self::order();
        $order['landing_site'] = '/collections/all?utm_source=newsletter';
        $this->assertArrayNotHasKey('ct', ShopifyOrderMapper::map($order));
    }

    public function testAddsTaxWhenPricesExcludeIt(): void
    {
        $order                   = self::order();
        $order['taxes_included'] = false;

        $this->assertSame('13.44', ShopifyOrderMapper::map($order)['items'][0]['unit_price'], '12.50 + 1.88 tax / 2');
    }

    public function testPartialRefundFromTransactionsAndRefundedLines(): void
    {
        $order                     = self::order();
        $order['financial_status'] = 'partially_refunded';
        $order['refunds']          = [[
            'id'                => 991,
            'transactions'      => [
                ['kind' => 'refund', 'status' => 'success', 'amount' => '12.50', 'currency' => 'CHF'],
                ['kind' => 'refund', 'status' => 'failure', 'amount' => '99.00', 'currency' => 'CHF'],
            ],
            'refund_line_items' => [['line_item_id' => 14120938045649, 'quantity' => 1, 'subtotal' => '13.20']],
        ]];

        $mapped = ShopifyOrderMapper::map($order);

        $this->assertSame('paid', $mapped['status'], 'Mautic derives partially_refunded from the refunded total.');
        $this->assertSame('12.5000', $mapped['refunded_total'], 'Failed transactions do not count.');
        $this->assertSame(1, $mapped['items'][0]['refunded_qty']);
        $this->assertSame(0, $mapped['items'][1]['refunded_qty']);
    }

    public function testRefundWithoutTransactionsUsesTheRefundedLines(): void
    {
        $order            = self::order();
        $order['refunds'] = [[
            'refund_line_items' => [[
                'line_item_id'  => 14120938045649,
                'quantity'      => 1,
                'subtotal_set'  => ['presentment_money' => ['amount' => '12.50', 'currency_code' => 'CHF']],
                'total_tax_set' => ['presentment_money' => ['amount' => '0.94', 'currency_code' => 'CHF']],
            ]],
        ]];

        $this->assertSame('13.4400', ShopifyOrderMapper::map($order)['refunded_total']);
    }

    public function testStatuses(): void
    {
        $order = self::order();

        foreach (['pending' => null, 'authorized' => null, 'partially_paid' => null, 'refunded' => 'refunded', 'voided' => 'cancelled'] as $financial => $expected) {
            $order['financial_status'] = $financial;
            $this->assertSame($expected, ShopifyOrderMapper::map($order)['status'] ?? null, $financial);
        }

        $order['cancelled_at']     = '2026-11-08T09:00:00+01:00';
        $order['financial_status'] = 'paid';
        $this->assertSame('cancelled', ShopifyOrderMapper::map($order)['status']);

        $order['financial_status'] = 'refunded';
        $this->assertSame('refunded', ShopifyOrderMapper::map($order)['status'], 'The money went back.');
    }

    public function testTestOrdersAndOtherBodiesAreSkipped(): void
    {
        $order         = self::order();
        $order['test'] = true;

        $this->assertNull(ShopifyOrderMapper::map($order));
        $this->assertNull(ShopifyOrderMapper::map(['id' => 1, 'title' => 'A product']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(): array
    {
        return WooCommercePlatformTest::fixture('shopify-order.json');
    }
}
