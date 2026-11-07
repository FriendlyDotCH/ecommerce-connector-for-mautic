<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\CommerceMetricsCalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

class CommerceMetricsFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();
    }

    public function testTimingFieldsFollowOrdersAndTheDailyRun(): void
    {
        $regular = $this->createContact('regular@example.com');
        $once    = $this->createContact('once@example.com');

        // Every 30 days: 90, 60 and 30 days ago, plus one cancelled order that must not count.
        foreach ([90, 60, 30] as $daysAgo) {
            $this->recordOrder($regular, 'reg-'.$daysAgo, "-{$daysAgo} days");
        }
        $this->recordOrder($regular, 'reg-cancelled', '-1 day', 'cancelled');
        $this->recordOrder($once, 'once-1', '-10 days');

        Assert::assertSame(30, (int) $this->value($regular, F::DAYS_SINCE_LAST_ORDER));
        Assert::assertEqualsWithDelta(30.0, (float) $this->value($regular, F::AVG_DAYS_BETWEEN_ORDERS), 0.1);
        Assert::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $this->value($regular, F::NEXT_ORDER_DATE));

        // One order: days since, but no rhythm yet.
        Assert::assertSame(10, (int) $this->value($once, F::DAYS_SINCE_LAST_ORDER));
        Assert::assertNull($this->value($once, F::AVG_DAYS_BETWEEN_ORDERS));
        Assert::assertNull($this->value($once, F::NEXT_ORDER_DATE));

        // Twenty days later, only the daily run moves "days since".
        $processed = static::getContainer()->get(CommerceMetricsCalculator::class)->recalculateAll(new \DateTimeImmutable('+20 days'));
        Assert::assertSame(2, $processed);
        Assert::assertSame(50, (int) $this->value($regular, F::DAYS_SINCE_LAST_ORDER));
        Assert::assertSame(30, (int) $this->value($once, F::DAYS_SINCE_LAST_ORDER));
    }

    public function testRecalculateCommandRuns(): void
    {
        $contact = $this->createContact('command-metrics@example.com');
        $this->recordOrder($contact, 'cmd-1', '-3 days');

        $output = $this->testSymfonyCommand('mautic:ecommerce:recalculate');

        Assert::assertSame(0, $output->getStatusCode());
        Assert::assertStringContainsString('timing: 1 contacts', $output->getDisplay());
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    private function recordOrder(Lead $lead, string $orderId, string $placed, ?string $status = null): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload($orderId, '10', 'web', 'EUR', null, null, null, [], new \DateTimeImmutable($placed), $status),
            $lead
        );
    }

    private function value(Lead $lead, string $column): mixed
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT %s FROM %sleads WHERE id = ?', $column, MAUTIC_TABLE_PREFIX),
            [$lead->getId()]
        );

        return false === $value ? null : $value;
    }
}
