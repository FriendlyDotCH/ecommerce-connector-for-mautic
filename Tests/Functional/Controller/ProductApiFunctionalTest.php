<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductApiFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testBatchUpsertsReportsErrorsAndFillsOrderCategories(): void
    {
        $lead = new Lead();
        $lead->setEmail('api-catalog@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload('P-1', '20', 'shop', 'EUR', null, null, null, [
            new OrderItemPayload('CND-1', 'Candle', 2, null, '10', 0, '501'),
        ]), $lead);

        $result = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', [
            'store'    => 'Shop',
            'products' => [
                ['id' => 501, 'name' => 'Lavender Candle', 'sku' => 'CND-1', 'price' => '12.5', 'currency' => 'eur', 'categories' => ['Candles', 'Gifts'], 'stock_status' => 'instock', 'stock_quantity' => 8, 'updated_at' => '2026-11-01T10:00:00+01:00'],
                ['id' => 502, 'name' => 'Cedar Diffuser', 'categories' => ['Diffusers'], 'stock_status' => 'outofstock'],
                ['name' => 'No id'],
                'not an object',
            ],
            'deleted' => [999],
        ]);

        Assert::assertSame(['501', '502'], array_column($result['products'], 'external_id'));
        $candle = $result['products'][0];
        Assert::assertSame('shop', $candle['store']);
        Assert::assertSame('12.5000', $candle['price']);
        Assert::assertSame('EUR', $candle['currency']);
        Assert::assertSame('Candles', $candle['category']);
        Assert::assertSame(['Candles', 'Gifts'], $candle['categories']);
        Assert::assertSame(8, $candle['stock_quantity']);
        Assert::assertSame('2026-11-01T09:00:00+00:00', $candle['updated_at']);
        Assert::assertSame([['store' => 'shop', 'external_id' => '999', 'existed' => false]], $result['deleted']);
        Assert::assertSame([2, 3], array_column($result['errors'], 'index'));
        Assert::assertSame('id and name are required.', $result['errors'][0]['message']);

        // The order line recorded before the product now has its category.
        Assert::assertSame('Candles', $this->connection->fetchOne('SELECT category FROM '.MAUTIC_TABLE_PREFIX."ecommerce_order_items WHERE item_id = 'CND-1'"));

        // Sending the same product again updates it in place.
        $updated = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['store' => 'shop', 'id' => 501, 'name' => 'Lavender Candle 200g', 'price' => '9.90'])['products'][0];
        Assert::assertSame($candle['id'], $updated['id']);
        Assert::assertSame('9.9000', $updated['price']);

        // Nothing applicable, too large, or empty: 400.
        $refused = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['products' => [['id' => 1, 'name' => 'No store']]], Response::HTTP_BAD_REQUEST);
        Assert::assertStringContainsString('store', $refused['errors'][0]['details']['errors'][0]['message']);
        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['store' => 'shop', 'deleted' => range(1, 251)], Response::HTTP_BAD_REQUEST);
        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', [], Response::HTTP_BAD_REQUEST);
    }

    public function testListFiltersPagingGetAndDelete(): void
    {
        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['store' => 'shop', 'products' => [
            ['id' => 1, 'name' => 'Lavender Candle', 'sku' => 'L-1', 'categories' => ['Candles'], 'stock_status' => 'instock'],
            ['id' => 2, 'name' => 'Scented Candle Set', 'categories' => ['Scented Candles'], 'stock_status' => 'outofstock'],
            ['id' => 3, 'name' => 'Gift Box', 'categories' => ['Gifts', 'Candles'], 'stock_status' => 'instock'],
        ]]);
        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['store' => 'outlet', 'id' => 1, 'name' => 'Lavender Candle (outlet)']);

        $all = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products');
        Assert::assertSame(4, $all['total']);

        Assert::assertSame(['1', '2', '3'], $this->externalIds('/api/ecommerce/products?store=SHOP'));
        Assert::assertSame(['1', '3'], $this->externalIds('/api/ecommerce/products?store=shop&category=Candles'), 'Main or any category, exact name.');
        Assert::assertSame(['2'], $this->externalIds('/api/ecommerce/products?stock_status=outofstock'));
        Assert::assertSame(['1'], $this->externalIds('/api/ecommerce/products?sku=L-1'));
        Assert::assertSame(['1', '1'], $this->externalIds('/api/ecommerce/products?external_id=1'));
        Assert::assertSame(['2'], $this->externalIds('/api/ecommerce/products?search=scented'));
        Assert::assertSame([], $this->externalIds('/api/ecommerce/products?since=2099-01-01'));
        Assert::assertCount(4, $this->externalIds('/api/ecommerce/products?since=2000-01-01'));

        $paged = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products?store=shop&limit=1&start=1');
        Assert::assertSame(3, $paged['total']);
        Assert::assertSame(['2'], array_column($paged['products'], 'external_id'));

        $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products?stock_status=sold', null, Response::HTTP_BAD_REQUEST);
        $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products?since=not-a-date', null, Response::HTTP_BAD_REQUEST);

        $id      = $all['products'][0]['id'];
        $product = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products/'.$id)['product'];
        Assert::assertSame('Lavender Candle', $product['name']);

        $deleted = $this->apiJson(Request::METHOD_DELETE, '/api/ecommerce/products/'.$id.'/delete')['product'];
        Assert::assertSame($id, $deleted['id']);
        $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products/'.$id, null, Response::HTTP_NOT_FOUND);
        $this->apiJson(Request::METHOD_DELETE, '/api/ecommerce/products/'.$id.'/delete', null, Response::HTTP_NOT_FOUND);

        // Deleting by store and shop ID through the batch.
        $batch = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/products/batch', ['store' => 'outlet', 'deleted' => [1]]);
        Assert::assertTrue($batch['deleted'][0]['existed']);
        Assert::assertSame(2, $this->apiJson(Request::METHOD_GET, '/api/ecommerce/products')['total']);
    }

    /**
     * @return list<string>
     */
    private function externalIds(string $uri): array
    {
        return array_column($this->apiJson(Request::METHOD_GET, $uri)['products'], 'external_id');
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function apiJson(string $method, string $uri, ?array $body = null, int $expectedStatus = Response::HTTP_OK): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        $response = $this->client->getResponse();

        Assert::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
