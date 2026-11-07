<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicControllerTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();
    }

    public function testWebhookRecordsOrderWithValidSignature(): void
    {
        $payload = [
            'order_id'       => 'functional-order-1',
            'order_total'    => '120.50',
            'order_source'   => 'web',
            'order_currency' => 'USD',
        ];

        $this->client->request(
            Request::METHOD_POST,
            '/ecommerce/order',
            $payload,
            [],
            [
                'HTTP_'.str_replace('-', '_', strtoupper(WebhookSignatureValidator::SIGNATURE_HEADER)) => $this->buildSignature($payload),
            ]
        );

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'functional-order-1']);
        Assert::assertNotNull($order);
        Assert::assertSame('120.5000', $order->getOrderTotal());
        Assert::assertSame('USD', $order->getOrderCurrency());
        Assert::assertNull($order->getLead());
    }

    public function testWebhookAcceptsSignedJsonBody(): void
    {
        $body = json_encode([
            'order_id'     => 'functional-order-json',
            'order_total'  => '15.25',
            'order_source' => 'WooCommerce',
            'location'     => 'hixson',
            'email'        => 'nobody@example.com',
            'items'        => [
                [
                    'item_id'   => '1722',
                    'item_name' => 'Smart Water 1 Liter',
                    'qty'       => 1,
                    'category'  => 'Beverages',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->client->request(
            Request::METHOD_POST,
            '/ecommerce/order',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_'.str_replace('-', '_', strtoupper(WebhookSignatureValidator::SIGNATURE_HEADER)) => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            ],
            $body
        );

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'functional-order-json']);
        Assert::assertNotNull($order);
        Assert::assertSame('15.2500', $order->getOrderTotal());
        Assert::assertSame('WooCommerce', $order->getOrderSource());
        Assert::assertNull($order->getLead());
        Assert::assertCount(1, $order->getItems());
    }

    public function testWebhookLinksExistingContactByEmailOnly(): void
    {
        $lead = new Lead();
        $lead->setEmail('jane@example.com');
        $this->em->persist($lead);
        $this->em->flush();

        $payload = [
            'order_id'     => 'functional-order-linked',
            'order_total'  => '24.99',
            'order_source' => 'web',
            'email'        => 'jane@example.com',
        ];

        $this->client->request(
            Request::METHOD_POST,
            '/ecommerce/order',
            $payload,
            [],
            [
                'HTTP_'.str_replace('-', '_', strtoupper(WebhookSignatureValidator::SIGNATURE_HEADER)) => $this->buildSignature($payload),
            ]
        );

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'functional-order-linked']);
        Assert::assertNotNull($order);
        Assert::assertNotNull($order->getLead());
        Assert::assertSame((int) $lead->getId(), (int) $order->getLead()->getId());
    }

    public function testWebhookDoesNotCreateContactForUnknownEmail(): void
    {
        $beforeCount = (int) $this->em->getRepository(Lead::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $payload = [
            'order_id'     => 'functional-order-unknown-email',
            'order_total'  => '10.00',
            'order_source' => 'web',
            'email'        => 'does-not-exist@example.com',
        ];

        $this->client->request(
            Request::METHOD_POST,
            '/ecommerce/order',
            $payload,
            [],
            [
                'HTTP_'.str_replace('-', '_', strtoupper(WebhookSignatureValidator::SIGNATURE_HEADER)) => $this->buildSignature($payload),
            ]
        );

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'functional-order-unknown-email']);
        Assert::assertNotNull($order);
        Assert::assertNull($order->getLead());

        $afterCount = (int) $this->em->getRepository(Lead::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
        Assert::assertSame($beforeCount, $afterCount);
    }

    public function testWebhookRejectsInvalidSignature(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/ecommerce/order',
            [
                'order_id'    => 'functional-order-2',
                'order_total' => '50.00',
            ],
            [],
            [
                'HTTP_'.str_replace('-', '_', strtoupper(WebhookSignatureValidator::SIGNATURE_HEADER)) => 'invalid',
            ]
        );

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        Assert::assertNull($this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'functional-order-2']));
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function buildSignature(array $payload): string
    {
        ksort($payload);

        return hash_hmac('sha256', http_build_query($payload), self::WEBHOOK_SECRET);
    }
}
