<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\WooCommerceOrderMapper;
use PHPUnit\Framework\TestCase;

class WooCommerceOrderMapperTest extends TestCase
{
    public function testMapsAPaidOrder(): void
    {
        $mapped = WooCommerceOrderMapper::map(self::order());

        $this->assertSame('4721', $mapped['order_id']);
        $this->assertSame('25866', $mapped['order_total']);
        $this->assertSame('HUF', $mapped['order_currency']);
        $this->assertSame('woocommerce', $mapped['order_source']);
        $this->assertSame('2026-11-07T18:02:40Z', $mapped['order_date'], 'Paid date, in UTC.');
        $this->assertSame('paid', $mapped['status']);
        $this->assertNull($mapped['refunded_total']);
        $this->assertSame('test@joeykeller.com', $mapped['email']);
        $this->assertSame('Budapest', $mapped['location']);

        $this->assertSame('CND-LAV-200', $mapped['items'][0]['item_id']);
        $this->assertSame(2, $mapped['items'][0]['qty']);
        $this->assertSame('4997.5', $mapped['items'][0]['unit_price'], '(7870.08 + 2124.92) / 2: including tax.');
        $this->assertSame('164', $mapped['items'][1]['item_id'], 'No SKU: the variation ID.');
    }

    public function testRefundsAndStatuses(): void
    {
        $order            = self::order();
        $order['refunds'] = [['id' => 5001, 'reason' => 'Broken jar', 'total' => '-3935.04'], ['id' => 5002, 'reason' => '', 'total' => '-1490']];
        $this->assertSame('5425.0400', WooCommerceOrderMapper::map($order)['refunded_total']);

        foreach (['completed' => 'paid', 'refunded' => 'refunded', 'cancelled' => 'cancelled'] as $woo => $ours) {
            $order['status'] = $woo;
            $this->assertSame($ours, WooCommerceOrderMapper::map($order)['status']);
        }

        foreach (['pending', 'on-hold', 'failed', 'checkout-draft'] as $unpaid) {
            $order['status'] = $unpaid;
            $this->assertNull(WooCommerceOrderMapper::map($order), $unpaid);
        }

        $this->assertNull(WooCommerceOrderMapper::map(['webhook_id' => '17']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/woocommerce-order.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
