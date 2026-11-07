<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\ReportBundle\Entity\Report;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ReportSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class ReportSubscriberFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testLineItemsReportHasOneRowPerItemWithLineTotal(): void
    {
        $lead = new Lead();
        $lead->setEmail('report-buyer@example.com');
        $this->em->persist($lead);
        $this->em->flush();

        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload('rep-1', '35.0000', 'pos', 'EUR', null, null, null, [
                new OrderItemPayload('c-1', 'Candle', 2, 'Candles', '12.5000'),
                new OrderItemPayload('s-1', 'Soap', 1, 'Bath', '10.0000'),
            ]),
            $lead
        );

        $report = new Report();
        $report->setName('Line items');
        $report->setSource(ReportSubscriber::CONTEXT_ECOMMERCE_ORDER_ITEMS);
        $report->setColumns(['eo.order_id', 'ei.item_name', 'ei.category', 'ei.qty', 'line_total', 'l.email']);
        $report->setTableOrder([['column' => 'ei.item_name', 'direction' => 'ASC']]);
        $this->em->persist($report);
        $this->em->flush();
        $this->em->clear();

        $this->client->request(Request::METHOD_GET, '/api/reports/'.$report->getId());
        $response = $this->client->getResponse();
        Assert::assertTrue($response->isOk(), (string) $response->getContent());

        $rows = json_decode((string) $response->getContent(), true)['data'];
        Assert::assertCount(2, $rows);
        Assert::assertSame(['Candle', 'Soap'], array_column($rows, 'item_name'));
        Assert::assertSame(['rep-1', 'rep-1'], array_column($rows, 'order_id'));
        Assert::assertEqualsWithDelta(25.0, (float) $rows[0]['line_total'], 0.001);
        Assert::assertSame('report-buyer@example.com', $rows[1]['email']);
    }
}
