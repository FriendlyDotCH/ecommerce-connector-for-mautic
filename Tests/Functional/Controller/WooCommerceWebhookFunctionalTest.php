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
use MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service\WooCommerceOrderMapperTest;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The order lifecycle as WooCommerce delivers it to a native webhook.
 */
class WooCommerceWebhookFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['default_currency' => 'HUF']);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();

        $this->customer = new Lead();
        $this->customer->setEmail('test@joeykeller.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($this->customer);
    }

    public function testOrderLifecycle(): void
    {
        // Creating the webhook in WooCommerce sends a form-encoded ping.
        $this->deliver('webhook_id=17', 'action.woocommerce_webhook_ping', 'application/x-www-form-urlencoded');
        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        // Checkout creates the order unpaid: nothing recorded yet.
        $order           = WooCommerceOrderMapperTest::order();
        $order['status'] = 'pending';
        $this->deliverOrder($order, 'order.created');
        Assert::assertNull($this->find());

        // Payment moves it to processing: recorded and linked by billing email.
        $order['status'] = 'processing';
        $this->deliverOrder($order, 'order.updated');
        $recorded = $this->find();
        Assert::assertInstanceOf(EcommerceOrder::class, $recorded);
        Assert::assertSame('25866.0000', $recorded->getOrderTotal());
        Assert::assertSame((int) $this->customer->getId(), (int) $recorded->getLead()?->getId());
        Assert::assertCount(2, $recorded->getItems());
        Assert::assertSame(25866.0, $this->lifetimeValue());

        // Completed, then delivered again: no duplicate, no change.
        $order['status'] = 'completed';
        $this->deliverOrder($order, 'order.updated');
        $this->deliverOrder($order, 'order.updated');
        Assert::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_orders'));

        // A partial refund of one candle.
        $order['refunds'] = [['id' => 5001, 'reason' => 'Broken jar', 'total' => '-3935.04']];
        $this->deliverOrder($order, 'order.updated');
        Assert::assertSame('partially_refunded', $this->find()->getStatus());
        Assert::assertEqualsWithDelta(21930.96, $this->lifetimeValue(), 0.01);

        // Then refunded in full.
        $order['status']  = 'refunded';
        $order['refunds'] = [['id' => 5001, 'total' => '-3935.04'], ['id' => 5002, 'total' => '-21930.96']];
        $this->deliverOrder($order, 'order.updated');
        Assert::assertSame('refunded', $this->find()->getStatus());
        Assert::assertSame(0.0, $this->lifetimeValue());
    }

    public function testWrongSignatureIsIgnored(): void
    {
        $order = WooCommerceOrderMapperTest::order();
        $body  = json_encode($order, JSON_THROW_ON_ERROR);

        $this->client->request(Request::METHOD_POST, '/ecommerce/woocommerce', [], [], [
            'CONTENT_TYPE'                => 'application/json',
            'HTTP_X_WC_WEBHOOK_TOPIC'     => 'order.updated',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true)),
        ], $body);

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        Assert::assertNull($this->find());
    }

    /**
     * @param array<string, mixed> $order
     */
    private function deliverOrder(array $order, string $topic): void
    {
        $this->deliver(json_encode($order, JSON_THROW_ON_ERROR), $topic, 'application/json');
    }

    /**
     * Signed and labelled as WooCommerce does.
     */
    private function deliver(string $body, string $topic, string $contentType): void
    {
        $this->client->request(Request::METHOD_POST, '/ecommerce/woocommerce', [], [], [
            'CONTENT_TYPE'                => $contentType,
            'HTTP_USER_AGENT'             => 'WooCommerce/9.4.2 Hookshot (WordPress/6.7)',
            'HTTP_X_WC_WEBHOOK_SOURCE'    => 'https://shop.example/',
            'HTTP_X_WC_WEBHOOK_TOPIC'     => $topic,
            'HTTP_X_WC_WEBHOOK_ID'        => '17',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, self::WEBHOOK_SECRET, true)),
        ], $body);
    }

    private function find(): ?EcommerceOrder
    {
        $this->em->clear();

        return $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => '4721', 'orderSource' => 'woocommerce']);
    }

    private function lifetimeValue(): float
    {
        return (float) $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', LeadCommerceFieldDefinition::LIFETIME_VALUE, MAUTIC_TABLE_PREFIX),
            [$this->customer->getId()]
        );
    }
}
