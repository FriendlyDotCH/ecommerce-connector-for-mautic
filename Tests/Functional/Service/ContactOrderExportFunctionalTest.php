<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\ContactOrderExportFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceExportHelper;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

class ContactOrderExportFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testContactExportIncludesOrderHistory(): void
    {
        $lead = new Lead();
        $lead->setEmail('export-buyer@example.com');

        /** @var LeadModel $leadModel */
        $leadModel = static::getContainer()->get(LeadModel::class);
        $leadModel->saveEntity($lead);

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload(
                'export-order-1',
                '42.5000',
                'woocommerce',
                'USD',
                null,
                null,
                'Hixson',
                [new OrderItemPayload('sku-42', 'Export Widget', 2, 'Widgets')]
            ),
            $lead
        );

        /** @var ExportHelper $exportHelper */
        $exportHelper = static::getContainer()->get(ExportHelper::class);
        Assert::assertInstanceOf(EcommerceExportHelper::class, $exportHelper);

        $this->em->clear();
        $exportedLead = $leadModel->getEntity($lead->getId());
        Assert::assertNotNull($exportedLead);

        $row    = $exportHelper->parseLeadToExport($exportedLead);
        $orders = json_decode($row[ContactOrderExportFormatter::EXPORT_ALIAS], true, 512, JSON_THROW_ON_ERROR);

        Assert::assertCount(1, $orders);
        Assert::assertSame('export-order-1', $orders[0]['order_id']);
        Assert::assertSame('42.50', $orders[0]['order_total']);
        Assert::assertSame('USD', $orders[0]['order_currency']);
        Assert::assertSame('woocommerce', $orders[0]['order_source']);
        Assert::assertSame('Hixson', $orders[0]['location']);
        Assert::assertSame('sku-42', $orders[0]['items'][0]['item_id']);
        Assert::assertSame('Export Widget', $orders[0]['items'][0]['item_name']);
        Assert::assertSame(2, $orders[0]['items'][0]['qty']);
        Assert::assertSame('Widgets', $orders[0]['items'][0]['category']);

        $singleContactExport = $exportHelper->exportDataAs(
            [
                ['alias' => 'id', 'value' => $lead->getId()],
                ['alias' => 'email', 'value' => 'export-buyer@example.com'],
            ],
            ExportHelper::EXPORT_TYPE_CSV,
            'contact_data_export-buyer.csv'
        );

        ob_start();
        $singleContactExport->sendContent();
        $csv = (string) ob_get_clean();

        Assert::assertStringContainsString(ContactOrderExportFormatter::EXPORT_ALIAS, $csv);
        Assert::assertStringContainsString('export-order-1', $csv);
        Assert::assertStringContainsString('Export Widget', $csv);
    }
}
