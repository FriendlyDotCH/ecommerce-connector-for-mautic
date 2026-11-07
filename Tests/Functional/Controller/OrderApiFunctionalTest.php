<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderApiFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $jane;

    private Lead $john;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();

        $this->jane = $this->createContact('jane-api@example.com');
        $this->john = $this->createContact('john-api@example.com');
    }

    public function testCreateRejectsDuplicatesInvalidDataAndUnknownContacts(): void
    {
        $order = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', [
            'order_id'       => 'api-1',
            'order_total'    => '40.00',
            'order_currency' => 'eur',
            'order_source'   => 'pos',
            'order_date'     => '2026-04-01T10:00:00Z',
            'email'          => 'jane-api@example.com',
            'items'          => [['item_id' => 'c-1', 'item_name' => 'Candle', 'qty' => 2, 'category' => 'Candles', 'unit_price' => '20']],
        ], Response::HTTP_CREATED)['order'];

        Assert::assertSame('api-1', $order['order_id']);
        Assert::assertSame('EUR', $order['order_currency']);
        Assert::assertSame('2026-04-01T10:00:00+00:00', $order['order_date']);
        Assert::assertSame((int) $this->jane->getId(), $order['contact_id']);
        Assert::assertSame('20.0000', $order['items'][0]['unit_price']);
        Assert::assertSame(1, $this->fieldValue($this->jane, LeadCommerceFieldDefinition::ORDER_COUNT));

        $duplicate = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', ['order_id' => 'api-1', 'order_source' => 'pos'], Response::HTTP_CONFLICT);
        Assert::assertSame($order['id'], $duplicate['errors'][0]['details']['id']);

        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', ['order_total' => '5'], Response::HTTP_BAD_REQUEST);
        $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', ['order_id' => 'api-x', 'contact_id' => 999999], Response::HTTP_BAD_REQUEST);

        // Unknown email: recorded without a contact, and no contact is created.
        $contactsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'leads');
        $anonymous      = $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', ['order_id' => 'api-2', 'email' => 'nobody@example.com'], Response::HTTP_CREATED);
        Assert::assertNull($anonymous['order']['contact_id']);
        Assert::assertSame($contactsBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'leads'));
    }

    public function testListFiltersAndContactOrders(): void
    {
        $this->createOrder('l-1', 'web', '2026-01-10T00:00:00Z', $this->jane);
        $this->createOrder('l-2', 'pos', '2026-02-10T00:00:00Z', $this->jane);
        $this->createOrder('l-3', 'pos', '2026-03-10T00:00:00Z', $this->john);

        $all = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/orders');
        Assert::assertSame(3, $all['total']);
        Assert::assertSame(['l-3', 'l-2', 'l-1'], array_column($all['orders'], 'order_id'));

        $pos = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/orders?source=pos&since=2026-03-01&limit=10');
        Assert::assertSame(['l-3'], array_column($pos['orders'], 'order_id'));

        $paged = $this->apiJson(Request::METHOD_GET, '/api/ecommerce/orders?limit=1&start=1');
        Assert::assertSame(3, $paged['total']);
        Assert::assertSame(['l-2'], array_column($paged['orders'], 'order_id'));

        $jane = $this->apiJson(Request::METHOD_GET, '/api/contacts/'.$this->jane->getId().'/ecommerce/orders');
        Assert::assertSame(['l-2', 'l-1'], array_column($jane['orders'], 'order_id'));

        $this->apiJson(Request::METHOD_GET, '/api/contacts/999999/ecommerce/orders', null, Response::HTTP_NOT_FOUND);
        $this->apiJson(Request::METHOD_GET, '/api/ecommerce/orders/999999', null, Response::HTTP_NOT_FOUND);
    }

    public function testEditAndDeleteResyncContacts(): void
    {
        $order = $this->createOrder('e-1', 'web', '2026-05-01T00:00:00Z', $this->jane);
        Assert::assertSame(10.0, (float) $this->fieldValue($this->jane, LeadCommerceFieldDefinition::LIFETIME_VALUE));

        // PATCH keeps unsent fields.
        $patched = $this->apiJson(Request::METHOD_PATCH, '/api/ecommerce/orders/'.$order['id'].'/edit', [
            'order_total' => '25.50',
            'items'       => [['item_id' => 'd-1', 'item_name' => 'Diffuser', 'qty' => 1, 'unit_price' => '25.50']],
        ])['order'];
        Assert::assertSame('25.5000', $patched['order_total']);
        Assert::assertSame('2026-05-01T00:00:00+00:00', $patched['order_date']);
        Assert::assertSame(['d-1'], array_column($patched['items'], 'item_id'));
        Assert::assertSame(25.5, (float) $this->fieldValue($this->jane, LeadCommerceFieldDefinition::LIFETIME_VALUE));

        // Moving the order to John re-syncs both contacts.
        $moved = $this->apiJson(Request::METHOD_PATCH, '/api/ecommerce/orders/'.$order['id'].'/edit', ['contact_id' => $this->john->getId()])['order'];
        Assert::assertSame((int) $this->john->getId(), $moved['contact_id']);
        Assert::assertSame(0, $this->fieldValue($this->jane, LeadCommerceFieldDefinition::ORDER_COUNT));
        Assert::assertNull($this->fieldValue($this->jane, LeadCommerceFieldDefinition::LAST_ORDER_DATE));
        Assert::assertSame(1, $this->fieldValue($this->john, LeadCommerceFieldDefinition::ORDER_COUNT));

        // Order ID and source cannot be changed.
        $kept = $this->apiJson(Request::METHOD_PUT, '/api/ecommerce/orders/'.$order['id'].'/edit', ['order_id' => 'other', 'order_total' => '1'])['order'];
        Assert::assertSame('e-1', $kept['order_id']);
        Assert::assertSame([], $kept['items']);

        $this->apiJson(Request::METHOD_DELETE, '/api/ecommerce/orders/'.$order['id'].'/delete');
        $this->apiJson(Request::METHOD_GET, '/api/ecommerce/orders/'.$order['id'], null, Response::HTTP_NOT_FOUND);
        Assert::assertSame(0, $this->fieldValue($this->john, LeadCommerceFieldDefinition::ORDER_COUNT));
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrder(string $orderId, string $source, string $date, Lead $lead): array
    {
        return $this->apiJson(Request::METHOD_POST, '/api/ecommerce/orders/new', [
            'order_id'     => $orderId,
            'order_total'  => '10',
            'order_source' => $source,
            'order_date'   => $date,
            'contact_id'   => $lead->getId(),
        ], Response::HTTP_CREATED)['order'];
    }

    /**
     * @param array<string, mixed>|null $body
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

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    private function fieldValue(Lead $lead, string $alias): int|float|string|null
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', $alias, MAUTIC_TABLE_PREFIX),
            [$lead->getId()]
        );

        if (null === $value || LeadCommerceFieldDefinition::LAST_ORDER_DATE === $alias) {
            return $value;
        }

        return LeadCommerceFieldDefinition::ORDER_COUNT === $alias ? (int) $value : (float) $value;
    }
}
