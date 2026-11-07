<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourFieldManager;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class ProductEndpointFunctionalTest extends MauticMysqlTestCase
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

    public function testBatchPushFillsCategoriesAndBehaviours(): void
    {
        // A behaviour on candle spend, and an order whose lines arrived without categories.
        $behaviour = new EcommerceBehaviour();
        $behaviour->setName('Candle spend');
        $behaviour->setMetric('spend');
        $behaviour->setScope('category');
        $behaviour->setValues('Candles');
        static::getContainer()->get(BehaviourFieldManager::class)->saveField($behaviour);
        $this->em->persist($behaviour);
        $this->em->flush();

        $lead = new Lead();
        $lead->setEmail('catalog-buyer@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload('C-1', '30', 'woocommerce', 'HUF', null, null, null, [
            new OrderItemPayload('CND-LAV-200', 'Lavender Candle', 2, null, '10', 0, '155'),
            new OrderItemPayload('164', 'Reed Diffuser - Cedar', 1, null, '10', 0, '160'),
        ]), $lead);
        Assert::assertSame(0.0, $this->behaviourValue($lead, $behaviour));

        $this->postSigned([
            'store'    => 'woocommerce',
            'products' => [
                ['id' => 155, 'name' => 'Lavender Candle 200g', 'sku' => 'CND-LAV-200', 'categories' => ['Candles']],
                ['id' => 160, 'name' => 'Reed Diffuser', 'categories' => ['Diffusers'], 'variation_ids' => [164]],
                ['name' => 'Broken: no id'],
            ],
        ]);

        Assert::assertSame(['155', '160'], $this->catalogIds());
        Assert::assertSame(['Candles', 'Diffusers'], $this->connection->fetchFirstColumn('SELECT category FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items ORDER BY item_name'));
        Assert::assertSame(20.0, $this->behaviourValue($lead, $behaviour), 'Recalculated after the batch.');

        // Update in place, then delete both ways.
        $this->postSigned(['store' => 'woocommerce', 'id' => 155, 'name' => 'Lavender Candle 200g', 'price' => '4990', 'stock_status' => 'outofstock']);
        Assert::assertSame(['4990.0000', 'outofstock'], array_values($this->connection->fetchAssociative('SELECT price, stock_status FROM '.MAUTIC_TABLE_PREFIX."ecommerce_products WHERE external_id = '155'")));

        $this->postSigned(['store' => 'woocommerce', 'deleted' => [155]]);
        $this->postSigned(['store' => 'woocommerce', 'products' => [['id' => 160, 'deleted' => true]]]);
        Assert::assertSame([], $this->catalogIds());
    }

    public function testUnsignedOrStorelessPushesChangeNothing(): void
    {
        $this->client->request(Request::METHOD_POST, '/ecommerce/product', [], [], ['CONTENT_TYPE' => 'application/json'], '{"store":"woocommerce","id":1,"name":"X"}');
        $this->postSigned(['id' => 2, 'name' => 'No store']);

        Assert::assertSame([], $this->catalogIds());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postSigned(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/product', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
        Assert::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return list<string>
     */
    private function catalogIds(): array
    {
        return $this->connection->fetchFirstColumn('SELECT external_id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_products ORDER BY external_id');
    }

    private function behaviourValue(Lead $lead, EcommerceBehaviour $behaviour): float
    {
        return (float) $this->connection->fetchOne(sprintf('SELECT %s FROM %sleads WHERE id = ?', $behaviour->getFieldAlias(), MAUTIC_TABLE_PREFIX), [$lead->getId()]);
    }
}
