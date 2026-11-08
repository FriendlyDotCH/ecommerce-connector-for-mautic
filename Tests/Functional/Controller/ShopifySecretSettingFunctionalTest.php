<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * The optional Shopify key is a field of Mautic's own plugin form, saved encrypted with the keys.
 */
class ShopifySecretSettingFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    public function testShopifyKeyIsOptionalAndSavedEncrypted(): void
    {
        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();

        $crawler = $this->client->request(Request::METHOD_GET, '/s/plugins/config/'.EcommerceConnectorIntegration::NAME);
        Assert::assertCount(1, $crawler->filter('input[name="integration_details[apiKeys][shopify_webhook_secret]"]'));

        // Saving without it works (it is optional).
        $form = $crawler->filter('form')->form();
        $form->setValues(['integration_details' => ['isPublished' => 1, 'apiKeys' => ['webhook_secret' => self::WEBHOOK_SECRET, 'shopify_webhook_secret' => '']]]);
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();
        $this->resetIntegrationHelperCache();
        Assert::assertNull(static::getContainer()->get(Config::class)->getShopifyWebhookSecret());

        $crawler = $this->client->request(Request::METHOD_GET, '/s/plugins/config/'.EcommerceConnectorIntegration::NAME);
        $form    = $crawler->filter('form')->form();
        $form->setValues(['integration_details' => ['isPublished' => 1, 'apiKeys' => ['webhook_secret' => self::WEBHOOK_SECRET, 'shopify_webhook_secret' => ' shpss_0123456789abcdef ']]]);
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $stored = $this->em->getRepository(Integration::class)->findOneBy(['name' => EcommerceConnectorIntegration::NAME])->getApiKeys();
        Assert::assertArrayHasKey('shopify_webhook_secret', $stored);
        Assert::assertStringNotContainsString('shpss_', $stored['shopify_webhook_secret'], 'Stored encrypted.');

        $this->resetIntegrationHelperCache();
        Assert::assertSame('shpss_0123456789abcdef', static::getContainer()->get(Config::class)->getShopifyWebhookSecret());
    }
}
