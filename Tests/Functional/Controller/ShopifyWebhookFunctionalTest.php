<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Platform\WooCommercePlatformTest;
use MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service\ShopifyOrderMapperTest;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orders, refunds, cancellations and products as Shopify delivers them to /ecommerce/webhook/shopify.
 */
class ShopifyWebhookFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    private const SHOPIFY_KEY = 'shopify-signing-key';

    protected $useCleanupRollback = false;

    private Lead $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['default_currency' => 'CHF'], ['shopify_webhook_secret' => self::SHOPIFY_KEY]);

        $this->customer = new Lead();
        $this->customer->setEmail('jane@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($this->customer);
    }

    public function testOrderLifecycleWithCatalogAndAttribution(): void
    {
        // The send behind the email link in the order's landing page.
        $email = new Email();
        $email->setName('Autumn candles');
        $email->setSubject('Autumn candles');
        $this->em->persist($email);
        $stat = new Stat();
        $stat->setEmail($email);
        $stat->setLead($this->em->getReference(Lead::class, $this->customer->getId()));
        $stat->setEmailAddress('jane@example.com');
        $stat->setTrackingHash('6aef89be59f70782047236');
        $stat->setDateSent(new \DateTime('-1 day'));
        $this->em->persist($stat);
        $this->em->flush();

        $this->deliver(WooCommercePlatformTest::fixture('shopify-product.json'), 'products/create');
        Assert::assertSame(
            ['external_id' => '8312345678001', 'category' => 'Candles', 'url' => 'https://friendly-candles.myshopify.com/products/lavender-candle'],
            $this->connection->fetchAssociative('SELECT external_id, category, url FROM '.MAUTIC_TABLE_PREFIX."ecommerce_products WHERE store = 'shopify'")
        );

        // Created unpaid: nothing yet.
        $order                     = ShopifyOrderMapperTest::order();
        $order['financial_status'] = 'pending';
        $this->deliver($order, 'orders/create');
        Assert::assertNull($this->find());

        // Paid: recorded, linked, categorised from the catalog, credited to the email.
        $order['financial_status'] = 'paid';
        $this->deliver($order, 'orders/updated');
        $recorded = $this->find();
        Assert::assertInstanceOf(EcommerceOrder::class, $recorded);
        Assert::assertSame('49.8000', $recorded->getOrderTotal());
        Assert::assertSame('CHF', $recorded->getOrderCurrency());
        Assert::assertSame((int) $this->customer->getId(), (int) $recorded->getLead()?->getId());
        Assert::assertSame((int) $email->getId(), (int) $this->connection->fetchOne('SELECT email_id FROM '.MAUTIC_TABLE_PREFIX."ecommerce_orders WHERE order_id = '1042'"));
        Assert::assertSame(['Candles', null], $this->connection->fetchFirstColumn('SELECT category FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items ORDER BY id'));

        // orders/paid arrives too: no duplicate.
        $this->deliver($order, 'orders/paid');
        Assert::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_orders'));

        // One candle refunded.
        $order['financial_status'] = 'partially_refunded';
        $order['refunds']          = [[
            'id'                => 991,
            'transactions'      => [['kind' => 'refund', 'status' => 'success', 'amount' => '12.50', 'currency' => 'CHF']],
            'refund_line_items' => [['line_item_id' => 14120938045649, 'quantity' => 1]],
        ]];
        $this->deliver($order, 'orders/updated');
        $refunded = $this->find();
        Assert::assertSame('partially_refunded', $refunded->getStatus());
        Assert::assertSame('37.3000', $refunded->getNetTotal());

        // Cancelled: counts nothing.
        $order['cancelled_at'] = '2026-11-08T09:00:00+01:00';
        $this->deliver($order, 'orders/cancelled');
        Assert::assertSame('cancelled', $this->find()->getStatus());

        // Product deleted.
        $this->deliver(['id' => 8312345678001], 'products/delete');
        Assert::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_products'));
    }

    public function testOnlyShopifysKeyIsAccepted(): void
    {
        // Signed with the plugin's own secret while a Shopify key is set: rejected.
        $this->deliver(ShopifyOrderMapperTest::order(), 'orders/create', self::WEBHOOK_SECRET);
        Assert::assertNull($this->find());

        $this->deliver(ShopifyOrderMapperTest::order(), 'orders/create');
        Assert::assertInstanceOf(EcommerceOrder::class, $this->find());
    }

    public function testTestOrdersAreNotRecorded(): void
    {
        $order         = ShopifyOrderMapperTest::order();
        $order['test'] = true;
        $this->deliver($order, 'orders/create');

        Assert::assertNull($this->find());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function deliver(array $body, string $topic, string $key = self::SHOPIFY_KEY): void
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/webhook/shopify', [], [], [
            'CONTENT_TYPE'                => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC'        => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN'  => 'friendly-candles.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID'   => 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043',
            'HTTP_X_SHOPIFY_HMAC_SHA256'  => base64_encode(hash_hmac('sha256', $json, $key, true)),
        ], $json);

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function find(): ?EcommerceOrder
    {
        $this->em->clear();

        return $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => '1042', 'orderSource' => 'shopify']);
    }
}
