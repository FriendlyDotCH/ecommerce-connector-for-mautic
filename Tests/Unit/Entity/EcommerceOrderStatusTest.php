<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Entity;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EcommerceOrderStatusTest extends TestCase
{
    public function testNewOrderIsPaidWithNetEqualToTotal(): void
    {
        $order = $this->order('100');

        $this->assertSame(EcommerceOrder::STATUS_PAID, $order->getStatus());
        $this->assertSame('100.0000', $order->getNetTotal());
        $this->assertTrue($order->isCounted());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: string, 3: string, 4: string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'partial refund amount' => [null, '30', 'partially_refunded', '30.0000', '70.0000'];
        yield 'refund of full amount' => ['partially_refunded', '100', 'refunded', '100.0000', '0.0000'];
        yield 'refunded without amount' => ['refunded', null, 'refunded', '100.0000', '0.0000'];
        yield 'refund capped at total' => [null, '150', 'refunded', '100.0000', '0.0000'];
        yield 'partly refunded without amount' => ['partially_refunded', '0', 'paid', '0.0000', '100.0000'];
        yield 'cancelled' => ['cancelled', null, 'cancelled', '0.0000', '0.0000'];
        yield 'unknown status' => ['shipped', null, 'paid', '0.0000', '100.0000'];
    }

    #[DataProvider('statusProvider')]
    public function testApplyStatus(?string $status, ?string $refunded, string $expectedStatus, string $expectedRefunded, string $expectedNet): void
    {
        $order = $this->order('100');
        $order->applyStatus($status, $refunded);

        $this->assertSame($expectedStatus, $order->getStatus());
        $this->assertSame($expectedRefunded, $order->getRefundedTotal());
        $this->assertSame($expectedNet, $order->getNetTotal());
    }

    public function testOnlyPaidAndPartlyRefundedCount(): void
    {
        $order = $this->order('100');

        $order->applyStatus('partially_refunded', '10');
        $this->assertTrue($order->isCounted());

        $order->applyStatus('refunded', null);
        $this->assertFalse($order->isCounted());

        $order->applyStatus('cancelled', null);
        $this->assertFalse($order->isCounted());
    }

    public function testRefundedQtyIsCappedAtQty(): void
    {
        $item = new EcommerceOrderItem();
        $item->setQty(2);
        $item->setRefundedQty(5);
        $this->assertSame(2, $item->getRefundedQty());

        $item->setRefundedQty(-1);
        $this->assertSame(0, $item->getRefundedQty());
    }

    private function order(string $total): EcommerceOrder
    {
        $order = new EcommerceOrder();
        $order->setOrderTotal($total);

        return $order;
    }

    public function testBaseNetTotalFollowsRateAndRefunds(): void
    {
        $order = $this->order('10');
        $this->assertNull($order->getBaseNetTotal());

        $order->setExchangeRate('400');
        $this->assertSame('400.00000000', $order->getExchangeRate());
        $this->assertSame('4000.0000', $order->getBaseNetTotal());

        $order->applyStatus('partially_refunded', '2.5');
        $this->assertSame('3000.0000', $order->getBaseNetTotal());

        $order->setExchangeRate(null);
        $this->assertNull($order->getBaseNetTotal());
    }
}
