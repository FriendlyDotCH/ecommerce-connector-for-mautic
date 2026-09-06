<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class PrivacyNoticeAjaxControllerFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    public function testDismissStoresChoiceInDatabaseAndHidesDashboardNotice(): void
    {
        $this->configurePublishedIntegration();

        /** @var PrivacyUpgradeNotice $notice */
        $notice = static::getContainer()->get(PrivacyUpgradeNotice::class);
        $notice->disableIntegrationAndShowNotice();
        $this->resetIntegrationHelperCache();

        Assert::assertTrue($notice->isPending());
        Assert::assertFalse($this->reloadIntegration()->getIsPublished());

        $this->client->request(Request::METHOD_GET, '/s/dashboard');
        $this->assertResponseIsSuccessful();
        Assert::assertStringContainsString('js-ecommerce-privacy-notice', (string) $this->client->getResponse()->getContent());
        Assert::assertStringContainsString('alert-danger', (string) $this->client->getResponse()->getContent());
        Assert::assertStringContainsString('ecommerce-privacy-notice-content', (string) $this->client->getResponse()->getContent());

        $this->setCsrfHeader();
        $this->client->xmlHttpRequest(Request::METHOD_POST, '/s/ajax', [
            'action' => 'plugin:EcommerceConnector:dismissPrivacyNotice',
        ]);
        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $response->getContent(), true);
        Assert::assertIsArray($payload);
        Assert::assertSame(1, $payload['success']);

        $this->em->clear();
        $this->resetIntegrationHelperCache();

        $integration = $this->reloadIntegration();
        Assert::assertSame(PrivacyUpgradeNotice::STATUS_DISMISSED, $integration->getFeatureSettings()[PrivacyUpgradeNotice::FEATURE_KEY]);
        Assert::assertFalse(static::getContainer()->get(PrivacyUpgradeNotice::class)->isPending());

        $this->client->request(Request::METHOD_GET, '/s/dashboard');
        $this->assertResponseIsSuccessful();
        Assert::assertStringNotContainsString('js-ecommerce-privacy-notice', (string) $this->client->getResponse()->getContent());
    }

    public function testDashboardDoesNotShowNoticeOnFreshInstall(): void
    {
        $this->configurePublishedIntegration();
        $this->resetIntegrationHelperCache();

        Assert::assertFalse(static::getContainer()->get(PrivacyUpgradeNotice::class)->isPending());

        $this->client->request(Request::METHOD_GET, '/s/dashboard');
        $this->assertResponseIsSuccessful();
        Assert::assertStringNotContainsString('js-ecommerce-privacy-notice', (string) $this->client->getResponse()->getContent());
    }

    private function configurePublishedIntegration(): void
    {
        $integration = $this->em->getRepository(Integration::class)
            ->findOneBy(['name' => EcommerceConnectorIntegration::NAME]);

        if (null === $integration) {
            $integration = new Integration();
            $integration->setName(EcommerceConnectorIntegration::NAME);
            $integration->setPlugin($this->getPluginEntity());
        }

        $integration->setIsPublished(true);
        $integration->setFeatureSettings([
            'enable_page_hit_tracking' => true,
        ]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->resetIntegrationHelperCache();
    }

    private function reloadIntegration(): Integration
    {
        $integration = $this->em->getRepository(Integration::class)
            ->findOneBy(['name' => EcommerceConnectorIntegration::NAME]);
        Assert::assertNotNull($integration);

        return $integration;
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

    private function getPluginEntity(): Plugin
    {
        $plugin = $this->em->getRepository(Plugin::class)
            ->findOneBy(['bundle' => 'EcommerceConnectorBundle']);

        if (null === $plugin) {
            $plugin = new Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
            $plugin->setVersion('1.3.0');
            $this->em->persist($plugin);
            $this->em->flush();
        }

        return $plugin;
    }
}
