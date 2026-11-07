<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\Service\PurchaseHistoryQuery;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

class PurchaseHistoryQueryFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private PurchaseHistoryQuery $query;

    private int $buyerId;

    private int $otherId;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->query = static::getContainer()->get(PurchaseHistoryQuery::class);
        $this->now   = new \DateTimeImmutable('2026-06-30 12:00:00', new \DateTimeZone('UTC'));

        $this->buyerId = $this->createLead('history-buyer@example.com');
        $this->otherId = $this->createLead('history-other@example.com');

        // Buyer: a recent web order with candles and soap, an old POS order with candles.
        $this->insertOrder($this->buyerId, 'w-1', '50.00', 'web', '2026-06-20 10:00:00', [
            ['c-1', 'Lavender Candle', 'Candles', 2, '15.00'],
            ['s-1', 'Soap', 'Bath', 1, '20.00'],
        ]);
        $this->insertOrder($this->buyerId, 'p-1', '30.00', 'pos', '2026-01-15 10:00:00', [
            ['c-1', 'Lavender Candle', 'Candles', 3, null],
        ]);
        $this->insertOrder($this->otherId, 'w-2', '99.00', 'web', '2026-06-29 10:00:00', [
            ['s-1', 'Soap', 'Bath', 1, '99.00'],
        ]);
    }

    public function testAllTimeTotals(): void
    {
        $spend = $this->query->aggregateForLeads(new PurchaseCriteria('spend'), [$this->buyerId, $this->otherId], $this->now);
        Assert::assertSame([$this->buyerId => 80.0, $this->otherId => 99.0], $spend);

        Assert::assertSame(2.0, $this->query->aggregateForLead(new PurchaseCriteria('orders'), $this->buyerId, $this->now));
        Assert::assertSame(6.0, $this->query->aggregateForLead(new PurchaseCriteria('qty'), $this->buyerId, $this->now));
    }

    public function testRollingWindowUsesOrderDate(): void
    {
        Assert::assertSame(50.0, $this->query->aggregateForLead(new PurchaseCriteria('spend', 'any', [], 30), $this->buyerId, $this->now));
        Assert::assertSame(1.0, $this->query->aggregateForLead(new PurchaseCriteria('orders', 'any', [], 30), $this->buyerId, $this->now));
    }

    public function testCategoryProductAndSourceScopes(): void
    {
        // Category spend is qty x unit price; the POS candles have no unit price.
        Assert::assertSame(30.0, $this->query->aggregateForLead(new PurchaseCriteria('spend', 'category', ['candles']), $this->buyerId, $this->now));
        Assert::assertSame(5.0, $this->query->aggregateForLead(new PurchaseCriteria('qty', 'category', ['Candles']), $this->buyerId, $this->now));
        Assert::assertSame(2.0, $this->query->aggregateForLead(new PurchaseCriteria('orders', 'product', ['Lavender Candle']), $this->buyerId, $this->now));
        Assert::assertSame(1.0, $this->query->aggregateForLead(new PurchaseCriteria('orders', 'product', ['s-1']), $this->buyerId, $this->now));
        Assert::assertSame(30.0, $this->query->aggregateForLead(new PurchaseCriteria('spend', 'source', ['POS']), $this->buyerId, $this->now));
        Assert::assertSame(3.0, $this->query->aggregateForLead(new PurchaseCriteria('qty', 'source', ['pos']), $this->buyerId, $this->now));
    }

    public function testContactsWithoutMatchesGetZero(): void
    {
        $values = $this->query->aggregateForLeads(new PurchaseCriteria('spend', 'category', ['Candles']), [$this->otherId, 0, -5], $this->now);

        Assert::assertSame([$this->otherId => 0.0], $values);
        Assert::assertSame([], $this->query->aggregateForLeads(new PurchaseCriteria('spend'), [], $this->now));
    }

    private function createLead(string $email): int
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $this->em->persist($lead);
        $this->em->flush();

        return (int) $lead->getId();
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int, 4: ?string}> $items
     */
    private function insertOrder(int $leadId, string $orderId, string $total, string $source, string $orderDate, array $items): void
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $this->connection->insert($prefix.'ecommerce_orders', [
            'lead_id'      => $leadId,
            'order_id'     => $orderId,
            'order_total'  => $total,
            'order_source' => $source,
            'order_date'   => $orderDate,
            'date_added'   => $orderDate,
        ]);
        $orderPk = (int) $this->connection->lastInsertId();

        foreach ($items as [$itemId, $itemName, $category, $qty, $unitPrice]) {
            $this->connection->insert($prefix.'ecommerce_order_items', [
                'order_id'   => $orderPk,
                'lead_id'    => $leadId,
                'item_id'    => $itemId,
                'item_name'  => $itemName,
                'category'   => $category,
                'qty'        => $qty,
                'unit_price' => $unitPrice,
            ]);
        }
    }
}
