<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class TrackingSubscriberFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();
    }

    public function testMtcEventRecordsOrderWhileLoggedInAsAdmin(): void
    {
        $lead = new Lead();
        $lead->setEmail('pagehit-admin@example.com');
        $this->em->persist($lead);
        $this->em->flush();

        $this->client->request(
            Request::METHOD_POST,
            '/mtc/event',
            [
                'page_url'       => 'https://wordpress.ddev.site/?order-received=99',
                'order_id'       => 'page-hit-admin-99',
                'order_total'    => '18.00',
                'order_source'   => 'WooCommerce',
                'order_currency' => 'USD',
                'email'          => 'pagehit-admin@example.com',
                'items'          => '[{"item_id":"28","item_name":"Shirt - Green","qty":1}]',
            ]
        );

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy([
            'orderId'     => 'page-hit-admin-99',
            'orderSource' => 'WooCommerce',
        ]);
        Assert::assertNotNull($order);
        Assert::assertSame('18.0000', $order->getOrderTotal());
        Assert::assertSame('USD', $order->getOrderCurrency());
        Assert::assertNotNull($order->getLead());
        Assert::assertSame($lead->getId(), $order->getLead()->getId());
        Assert::assertCount(1, $order->getItems());
        Assert::assertSame('Shirt - Green', $order->getItems()->first()->getItemName());
    }

    public function testMtcEventRecordsOrderForAnonymousVisitor(): void
    {
        $this->logoutUser();

        $lead = new Lead();
        $lead->setEmail('pagehit-anon@example.com');
        $this->em->persist($lead);
        $this->em->flush();

        $this->client->request(
            Request::METHOD_POST,
            '/mtc/event',
            [
                'page_url'       => 'https://wordpress.ddev.site/?order-received=100',
                'order_id'       => 'page-hit-anon-100',
                'order_total'    => '25.50',
                'order_source'   => 'WooCommerce',
                'order_currency' => 'USD',
                'email'          => 'pagehit-anon@example.com',
            ]
        );

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());

        $order = $this->em->getRepository(EcommerceOrder::class)->findOneBy([
            'orderId'     => 'page-hit-anon-100',
            'orderSource' => 'WooCommerce',
        ]);
        Assert::assertNotNull($order);
        Assert::assertSame('25.5000', $order->getOrderTotal());
        Assert::assertNotNull($order->getLead());
        Assert::assertSame($lead->getId(), $order->getLead()->getId());
    }

    private function configureIntegration(): void
    {
        /** @var EncryptionHelper $encryptionHelper */
        $encryptionHelper = static::getContainer()->get(EncryptionHelper::class);

        $integration = $this->em->getRepository(Integration::class)
            ->findOneBy(['name' => EcommerceConnectorIntegration::NAME]);

        if (null === $integration) {
            $integration = new Integration();
            $integration->setName(EcommerceConnectorIntegration::NAME);
            $integration->setPlugin($this->getPluginEntity());
        }

        $integration->setIsPublished(true);
        $integration->setApiKeys([
            'webhook_secret' => $encryptionHelper->encrypt('test-webhook-secret'),
        ]);
        $integration->setFeatureSettings([
            'enable_page_hit_tracking' => true,
            'attribute_last_email'     => false,
            'allowed_order_sources'    => 'WooCommerce',
            'default_currency'         => 'USD',
            'max_order_total'          => 1000000,
        ]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->resetIntegrationHelperCache();
    }

    private function resetIntegrationHelperCache(): void
    {
        $integrationHelper = static::getContainer()->get('mautic.helper.integration');
        $reflection        = new \ReflectionClass($integrationHelper);

        foreach (['available', 'integrations', 'byFeatureList', 'byPlugin'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($integrationHelper, []);
        }
    }

    private function getPluginEntity(): \Mautic\PluginBundle\Entity\Plugin
    {
        $plugin = $this->em->getRepository(\Mautic\PluginBundle\Entity\Plugin::class)
            ->findOneBy(['bundle' => 'EcommerceConnectorBundle']);

        if (null === $plugin) {
            $plugin = new \Mautic\PluginBundle\Entity\Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
            $plugin->setVersion('1.3.0');
            $this->em->persist($plugin);
            $this->em->flush();
            static::getContainer()->get('cache.app')->clear();
        }

        return $plugin;
    }
}
