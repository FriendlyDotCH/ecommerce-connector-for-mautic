<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicControllerTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

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
            '/mtc/ecommerce',
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
    }

    public function testWebhookRejectsInvalidSignature(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/mtc/ecommerce',
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

    private function configureIntegration(): void
    {
        /** @var EncryptionHelper $encryptionHelper */
        $encryptionHelper = static::getContainer()->get('mautic.helper.encryption');

        $integration = $this->em->getRepository(Integration::class)
            ->findOneBy(['name' => EcommerceConnectorIntegration::NAME]);

        if (null === $integration) {
            $integration = new Integration();
            $integration->setName(EcommerceConnectorIntegration::NAME);
            $integration->setPlugin($this->getPluginEntity());
        }

        $integration->setIsPublished(true);
        $integration->setApiKeys([
            'webhook_secret' => $encryptionHelper->encrypt(self::WEBHOOK_SECRET),
        ]);
        $integration->setFeatureSettings([
            'enable_page_hit_tracking' => true,
            'attribute_last_email'     => false,
            'default_currency'           => 'USD',
            'max_order_total'            => 1000000,
        ]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->resetIntegrationHelperCache();
    }

    private function resetIntegrationHelperCache(): void
    {
        $integrationHelper = static::getContainer()->get('mautic.helper.integration');
        $reflection = new \ReflectionClass($integrationHelper);

        foreach (['available', 'integrations', 'byFeatureList', 'byPlugin'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($integrationHelper, []);
        }
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function buildSignature(array $payload): string
    {
        ksort($payload);

        return hash_hmac('sha256', http_build_query($payload), self::WEBHOOK_SECRET);
    }

    private function getPluginEntity(): \Mautic\PluginBundle\Entity\Plugin
    {
        $plugin = $this->em->getRepository(\Mautic\PluginBundle\Entity\Plugin::class)
            ->findOneBy(['bundle' => 'EcommerceConnectorBundle']);

        if (null === $plugin) {
            $plugin = new \Mautic\PluginBundle\Entity\Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
            $plugin->setVersion('1.1.0');
        $this->em->persist($plugin);
        $this->em->flush();
        static::getContainer()->get('cache.app')->clear();
        }

        return $plugin;
    }
}
