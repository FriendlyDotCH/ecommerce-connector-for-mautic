<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\CatalogSync;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * The email builder's product block: category list and example cards.
 */
class ProductBlockAjaxFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);

        $catalog = static::getContainer()->get(CatalogSync::class);
        foreach ([
            ['external_id' => '1', 'name' => 'Lavender Candle', 'category' => 'Candles', 'categories' => ['Candles', 'Gifts'], 'price' => '12.5000', 'stock_status' => 'instock', 'url' => 'https://shop.example/candle'],
            ['external_id' => '2', 'name' => 'Cedar Diffuser', 'category' => 'diffusers', 'categories' => ['diffusers'], 'price' => '20.0000', 'stock_status' => 'instock'],
            ['external_id' => '3', 'name' => 'No category'],
        ] as $product) {
            $catalog->upsert('shop', $product);
        }
    }

    public function testCategoriesListsMainAndAllCategoriesSorted(): void
    {
        Assert::assertSame(['Candles', 'diffusers', 'Gifts'], $this->ajax('productCategories')['categories']);
    }

    public function testPreviewRendersCardsAndMarksPersonalSourcesAsExample(): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload('B-1', '25', 'shop', 'CHF', null, null, null, [
            new OrderItemPayload('1', 'Lavender Candle', 2, 'Candles', '12.5', 0, '1'),
        ]), null);

        $best = $this->ajax('productBlockPreview', ['source' => 'best_sellers', 'limit' => '3', 'category' => 'Gifts']);
        Assert::assertFalse($best['example']);
        Assert::assertStringContainsString('Lavender Candle', $best['html']);
        Assert::assertStringContainsString('https://shop.example/candle', $best['html']);

        $viewed = $this->ajax('productBlockPreview', ['source' => 'viewed', 'limit' => '2', 'category' => 'Nothing']);
        Assert::assertTrue($viewed['example'], 'Contact-based sources show best sellers as an example; the category is ignored.');
        Assert::assertStringContainsString('Lavender Candle', $viewed['html']);

        Assert::assertSame('', $this->ajax('productBlockPreview', ['source' => 'best_sellers', 'category' => 'Unknown'])['html']);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function ajax(string $action, array $params = []): array
    {
        $this->client->xmlHttpRequest(Request::METHOD_GET, '/s/ajax', ['action' => 'plugin:EcommerceConnector:'.$action] + $params);
        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
