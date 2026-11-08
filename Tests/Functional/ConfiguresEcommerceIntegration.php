<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Publishes the integration with a known webhook secret. For MauticMysqlTestCase.
 */
trait ConfiguresEcommerceIntegration
{
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    /**
     * @param array<string, mixed> $featureSettings
     */
    private function configureIntegration(array $featureSettings = []): void
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
        $integration->setFeatureSettings(array_merge([
            'enable_page_hit_tracking' => true,
            'attribute_last_email'     => false,
            'default_currency'         => 'USD',
            'max_order_total'          => 1000000,
        ], $featureSettings));

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

    /**
     * @param array<string, mixed> $payload
     */
    private function postSignedJsonOrder(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

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

        // Webhooks always answer 204; anything else means a listener failed after the order was stored.
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function getPluginEntity(): Plugin
    {
        $plugin = $this->em->getRepository(Plugin::class)
            ->findOneBy(['bundle' => 'EcommerceConnectorBundle']);

        if (null === $plugin) {
            $plugin = new Plugin();
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
