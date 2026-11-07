<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

/**
 * Stores re-send an order when it is refunded or cancelled; the existing order is updated.
 */
class RefundWebhookFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();

        $this->buyer = new Lead();
        $this->buyer->setEmail('refund-buyer@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($this->buyer);
    }

    public function testPartialRefundThenCancellationUpdateOrderAndContact(): void
    {
        $this->postSignedJsonOrder($this->order([]));
        $this->postSignedJsonOrder($this->order(['order_id' => 'R-2', 'order_total' => '40.00']));

        Assert::assertSame(140.0, $this->field(LeadCommerceFieldDefinition::LIFETIME_VALUE));
        Assert::assertSame(2.0, $this->field(LeadCommerceFieldDefinition::ORDER_COUNT));

        // Plain repeat: ignored.
        $this->postSignedJsonOrder($this->order([]));
        Assert::assertSame(140.0, $this->field(LeadCommerceFieldDefinition::LIFETIME_VALUE));

        // Partial refund of one candle, sent the way WooCommerce names it.
        $this->postSignedJsonOrder($this->order([
            'status'         => 'partially-refunded',
            'refunded_total' => '25.00',
            'items'          => [['item_id' => 'C-1', 'item_name' => 'Candle', 'qty' => 4, 'category' => 'Candles', 'unit_price' => '25.00', 'refunded_qty' => 1]],
        ]));

        $order = $this->findOrder('R-1');
        Assert::assertSame('partially_refunded', $order->getStatus());
        Assert::assertSame('75.0000', $order->getNetTotal());
        Assert::assertSame(1, $order->getItems()->first()->getRefundedQty());
        Assert::assertSame('100.0000', $order->getOrderTotal(), 'The original total is kept.');
        Assert::assertSame(115.0, $this->field(LeadCommerceFieldDefinition::LIFETIME_VALUE));
        Assert::assertSame(2.0, $this->field(LeadCommerceFieldDefinition::ORDER_COUNT));

        // Cancelling R-2 removes it from the count and the value.
        $this->postSignedJsonOrder($this->order(['order_id' => 'R-2', 'order_total' => '40.00', 'status' => 'cancelled']));

        Assert::assertSame('cancelled', $this->findOrder('R-2')->getStatus());
        Assert::assertSame(75.0, $this->field(LeadCommerceFieldDefinition::LIFETIME_VALUE));
        Assert::assertSame(1.0, $this->field(LeadCommerceFieldDefinition::ORDER_COUNT));
    }

    public function testOrderArrivingRefundedIsStoredButNotCounted(): void
    {
        $this->postSignedJsonOrder($this->order(['status' => 'refunded']));

        $order = $this->findOrder('R-1');
        Assert::assertSame('refunded', $order->getStatus());
        Assert::assertSame('0.0000', $order->getNetTotal());
        Assert::assertSame(0.0, $this->field(LeadCommerceFieldDefinition::ORDER_COUNT));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function order(array $overrides): array
    {
        return array_merge([
            'order_id'     => 'R-1',
            'order_total'  => '100.00',
            'order_source' => 'woocommerce',
            'email'        => 'refund-buyer@example.com',
            'items'        => [['item_id' => 'C-1', 'item_name' => 'Candle', 'qty' => 4, 'category' => 'Candles', 'unit_price' => '25.00']],
        ], $overrides);
    }

    private function findOrder(string $orderId): EcommerceOrder
    {
        $this->em->clear();
        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => $orderId]);
        Assert::assertInstanceOf(EcommerceOrder::class, $order);

        return $order;
    }

    private function field(string $alias): float
    {
        return (float) $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', $alias, MAUTIC_TABLE_PREFIX),
            [$this->buyer->getId()]
        );
    }
}
