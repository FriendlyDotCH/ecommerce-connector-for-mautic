<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\CommerceRecalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use Mautic\LeadBundle\Entity\LeadField;
use PHPUnit\Framework\Assert;

class LifecycleFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();
    }

    public function testStageFieldIsASelectWithStages(): void
    {
        $field = $this->em->getRepository(LeadField::class)->findOneBy(['alias' => F::LIFECYCLE_STAGE]);

        Assert::assertInstanceOf(LeadField::class, $field);
        Assert::assertSame('select', $field->getType());
        Assert::assertSame(F::STAGES, array_column($field->getProperties()['list'], 'value'));
    }

    public function testDailyRunAssignsStagesAndRfmScores(): void
    {
        $contacts = [];

        // Ten customers, so the top 10% VIP rule applies: spend 100, 200, ... 1000.
        for ($i = 1; $i <= 10; ++$i) {
            $contacts[$i] = $this->createContact("rfm{$i}@example.com");
            $this->recordOrder($contacts[$i], "rfm-{$i}", (string) ($i * 100), '-'.(5 * $i).' days');
        }
        // Customer 2 orders every 30 days and is on time.
        $this->recordOrder($contacts[2], 'rfm-2b', '10', '-40 days');
        // Customer 3 bought once, 120 days ago... and once 10 days after that: overdue.
        $this->recordOrder($contacts[3], 'rfm-3b', '10', '-130 days');
        $this->connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX."ecommerce_orders SET order_date = DATE_SUB(NOW(), INTERVAL 120 DAY) WHERE order_id = 'rfm-3'");
        // Customer 9's only order is from two years ago.
        $this->connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX."ecommerce_orders SET order_date = DATE_SUB(NOW(), INTERVAL 700 DAY) WHERE order_id = 'rfm-9'");

        $result = static::getContainer()->get(CommerceRecalculator::class)->recalculateAll();
        Assert::assertSame(10, $result['lifecycle']);

        Assert::assertSame('new', $this->value($contacts[1], F::LIFECYCLE_STAGE));
        Assert::assertSame('active', $this->value($contacts[2], F::LIFECYCLE_STAGE));
        Assert::assertSame('at_risk', $this->value($contacts[3], F::LIFECYCLE_STAGE));
        Assert::assertSame('lapsed', $this->value($contacts[9], F::LIFECYCLE_STAGE));
        Assert::assertSame('vip', $this->value($contacts[10], F::LIFECYCLE_STAGE), 'Top 10% by spend');

        // Most recent and lowest spend; highest spend.
        Assert::assertSame('5', substr((string) $this->value($contacts[1], F::RFM_SCORE), 0, 1));
        Assert::assertSame(5, (int) $this->value($contacts[10], F::RFM_MONETARY));
        Assert::assertSame(1, (int) $this->value($contacts[1], F::RFM_MONETARY));
        Assert::assertMatchesRegularExpression('/^[1-5]{3}$/', (string) $this->value($contacts[5], F::RFM_SCORE));

        // A new order rescoring a single contact uses the cached cutoffs.
        $this->recordOrder($contacts[9], 'rfm-9b', '50', 'now');
        Assert::assertSame('active', $this->value($contacts[9], F::LIFECYCLE_STAGE));
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    private function recordOrder(Lead $lead, string $orderId, string $total, string $placed): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload($orderId, $total, 'web', 'EUR', null, null, null, [], new \DateTimeImmutable($placed)),
            $lead
        );
    }

    private function value(Lead $lead, string $column): mixed
    {
        return $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', $column, MAUTIC_TABLE_PREFIX),
            [$lead->getId()]
        );
    }
}
