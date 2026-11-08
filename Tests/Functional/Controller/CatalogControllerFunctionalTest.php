<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class CatalogControllerFunctionalTest extends MauticMysqlTestCase
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

    public function testListShowsProductsSalesAndFilters(): void
    {
        $products = static::getContainer()->get(EcommerceProductRepository::class);
        $products->upsertProduct('woocommerce', ['external_id' => '155', 'sku' => 'CND-1', 'name' => 'Lavender Candle', 'price' => '20', 'regular_price' => '25', 'sale_price' => '20', 'currency' => 'CHF', 'category' => 'Candles', 'stock_status' => 'instock', 'stock_quantity' => 7, 'url' => 'https://shop.example/candle', 'status' => 'publish']);
        $products->upsertProduct('woocommerce', ['external_id' => '160', 'name' => 'Reed <Diffuser>', 'price' => '30', 'category' => 'Diffusers', 'stock_status' => 'outofstock', 'variation_ids' => ['164']]);

        $lead = new Lead();
        $lead->setEmail('catalog@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);
        // 3 candles by product ID, 1 refunded; 2 diffusers by variation ID.
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload('CAT-1', '100', 'woocommerce', 'CHF', null, null, null, [
            new OrderItemPayload('CND-1', 'Lavender Candle', 3, 'Candles', '20', 1, '155'),
            new OrderItemPayload('164', 'Reed Diffuser - Cedar', 2, null, '30'),
        ]), $lead);

        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/catalog');
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());

        $rows = $crawler->filter('#ecommerceCatalogTable tbody tr');
        Assert::assertCount(2, $rows);
        Assert::assertStringContainsString('Lavender Candle', $rows->eq(0)->text());
        Assert::assertStringContainsString('25.00', $rows->eq(0)->filter('s')->text());
        Assert::assertSame('2', trim($rows->eq(0)->filter('td')->eq(6)->text()), 'Refunded units are not sold units.');
        Assert::assertSame('2', trim($rows->eq(1)->filter('td')->eq(6)->text()));
        Assert::assertStringContainsString('Reed &lt;Diffuser&gt;', (string) $this->client->getResponse()->getContent(), 'Shop data is escaped.');

        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/catalog?stock=outofstock');
        Assert::assertCount(1, $crawler->filter('#ecommerceCatalogTable tbody tr'));

        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/catalog?search=cnd-1');
        Assert::assertCount(1, $crawler->filter('#ecommerceCatalogTable tbody tr'));
        Assert::assertStringContainsString('Lavender', $crawler->filter('#ecommerceCatalogTable tbody tr')->text());
    }
}
