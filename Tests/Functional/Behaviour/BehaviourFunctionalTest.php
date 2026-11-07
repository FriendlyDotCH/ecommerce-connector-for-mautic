<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Behaviour;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourCalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourFieldManager;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class BehaviourFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testNewOrdersUpdateBehaviourFieldsAndRecalculationDecays(): void
    {
        $behaviour = $this->createBehaviour('Candle spend 30d', 'spend', 'category', 'Candles', 30);
        Assert::assertSame('ecb_candle_spend_30d', $behaviour->getFieldAlias());

        $buyer = $this->createContact('behaviour-buyer@example.com');

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder($this->payload('b-1', '-5 days', [['Candles', 2, '12.50'], ['Bath', 1, '9.00']]), $buyer);
        $tracker->recordOrder($this->payload('b-2', '-1 hour', [['Candles', 1, '10.00']]), $buyer);

        Assert::assertSame(35.0, $this->fieldValue($buyer, $behaviour));

        // The ecommerce field group is hidden on the profile, so the eCommerce tab lists behaviours.
        $this->configureIntegration();
        $this->client->request(Request::METHOD_GET, '/s/contacts/view/'.$buyer->getId());
        $page = (string) $this->client->getResponse()->getContent();
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertStringContainsString('Candle spend 30d', $page);
        Assert::assertMatchesRegularExpression('/35[.,]00/', $page, 'Formatting follows the user locale.');

        // Thirty-one days later, b-1 has left the window; only the daily run moves it.
        /** @var BehaviourCalculator $calculator */
        $calculator = static::getContainer()->get(BehaviourCalculator::class);
        $calculator->recalculate($behaviour, new \DateTimeImmutable('+26 days'));
        Assert::assertSame(10.0, $this->fieldValue($buyer, $behaviour));

        $calculator->recalculate($behaviour, new \DateTimeImmutable('+40 days'));
        Assert::assertSame(0.0, $this->fieldValue($buyer, $behaviour));
        Assert::assertNotNull($behaviour->getLastCalculated());
    }

    public function testCommandRecalculatesPublishedBehaviours(): void
    {
        $orders    = $this->createBehaviour('Order count', 'orders', 'any', null, 0);
        $paused    = $this->createBehaviour('Paused qty', 'qty', 'any', null, 0, false);
        $buyer     = $this->createContact('command-buyer@example.com');

        $prefix = MAUTIC_TABLE_PREFIX;
        foreach (['c-1', 'c-2', 'c-3'] as $orderId) {
            $this->connection->insert($prefix.'ecommerce_orders', [
                'lead_id' => $buyer->getId(), 'order_id' => $orderId, 'order_total' => 5, 'order_source' => 'pos',
                'order_date' => '2026-01-01 00:00:00', 'date_added' => '2026-01-01 00:00:00',
            ]);
        }

        $output = $this->testSymfonyCommand('mautic:ecommerce:behaviours:update');

        Assert::assertSame(0, $output->getStatusCode());
        Assert::assertStringContainsString('Order count', $output->getDisplay());
        Assert::assertStringNotContainsString('Paused qty', $output->getDisplay());
        Assert::assertSame(3.0, $this->fieldValue($buyer, $orders));
        Assert::assertSame(0.0, $this->fieldValue($buyer, $paused));

        $output = $this->testSymfonyCommand('mautic:ecommerce:behaviours:update', ['--id' => 999999]);
        Assert::assertSame(1, $output->getStatusCode());
    }

    public function testAdminCanCreateListAndDeleteBehaviours(): void
    {
        $this->client->request(Request::METHOD_GET, '/s/ecommerce/behaviours');
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertStringContainsString('Ecommerce behaviours', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/behaviours/new');
        Assert::assertTrue($this->client->getResponse()->isOk());

        $form = $crawler->selectButton('ecommerce_behaviour[buttons][save]')->form();
        $form->setValues([
            'ecommerce_behaviour[name]'   => 'Diffuser buyers',
            'ecommerce_behaviour[metric]' => 'qty',
            'ecommerce_behaviour[days]'   => '90',
            'ecommerce_behaviour[scope]'  => 'product',
            'ecommerce_behaviour[values]' => 'diffuser-1, Diffuser Deluxe',
        ]);
        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk(), (string) $this->client->getResponse()->getContent());

        $behaviour = $this->em->getRepository(EcommerceBehaviour::class)->findOneBy(['name' => 'Diffuser buyers']);
        Assert::assertInstanceOf(EcommerceBehaviour::class, $behaviour);
        Assert::assertSame('ecb_diffuser_buyers', $behaviour->getFieldAlias());
        Assert::assertSame('diffuser-1, Diffuser Deluxe', $behaviour->getValues());
        Assert::assertSame(90, $behaviour->getDays());
        Assert::assertArrayHasKey('ecb_diffuser_buyers', $this->leadColumns());

        $this->client->request(Request::METHOD_GET, '/s/ecommerce/behaviours');
        Assert::assertStringContainsString('{contactfield=ecb_diffuser_buyers}', (string) $this->client->getResponse()->getContent());

        // Without the CSRF header the delete is refused.
        $this->client->xmlHttpRequest(Request::METHOD_POST, '/s/ecommerce/behaviours/delete/'.$behaviour->getId());
        $this->em->clear();
        Assert::assertNotNull($this->em->getRepository(EcommerceBehaviour::class)->find($behaviour->getId()));

        $this->client->request(Request::METHOD_POST, '/s/ecommerce/behaviours/delete/'.$behaviour->getId(), [], [], $this->createAjaxHeaders());
        Assert::assertTrue($this->client->getResponse()->isOk());

        $this->em->clear();
        Assert::assertNull($this->em->getRepository(EcommerceBehaviour::class)->find($behaviour->getId()));
        Assert::assertArrayNotHasKey('ecb_diffuser_buyers', $this->leadColumns());
    }

    private function createBehaviour(string $name, string $metric, string $scope, ?string $values, int $days, bool $published = true): EcommerceBehaviour
    {
        $behaviour = new EcommerceBehaviour();
        $behaviour->setName($name);
        $behaviour->setMetric($metric);
        $behaviour->setScope($scope);
        $behaviour->setValues($values);
        $behaviour->setDays($days);
        $behaviour->setIsPublished($published);

        static::getContainer()->get(BehaviourFieldManager::class)->saveField($behaviour);
        $this->em->persist($behaviour);
        $this->em->flush();

        return $behaviour;
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    /**
     * @param list<array{0: string, 1: int, 2: string}> $items category, qty, unit price
     */
    private function payload(string $orderId, string $placed, array $items): OrderPayload
    {
        $itemPayloads = [];
        foreach ($items as $index => [$category, $qty, $unitPrice]) {
            $itemPayloads[] = new OrderItemPayload($orderId.'-'.$index, $category.' item', $qty, $category, $unitPrice);
        }

        return new OrderPayload($orderId, '0', 'web', 'EUR', null, null, null, $itemPayloads, new \DateTimeImmutable($placed));
    }

    private function fieldValue(Lead $lead, EcommerceBehaviour $behaviour): float
    {
        return (float) $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', $this->connection->quoteIdentifier((string) $behaviour->getFieldAlias()), MAUTIC_TABLE_PREFIX),
            [$lead->getId()]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function leadColumns(): array
    {
        return $this->connection->createSchemaManager()->listTableColumns(MAUTIC_TABLE_PREFIX.'leads');
    }
}
