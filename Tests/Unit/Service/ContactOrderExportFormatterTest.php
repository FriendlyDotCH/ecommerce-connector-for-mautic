<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\ContactOrderExportFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactOrderExportFormatterTest extends TestCase
{
    private EcommerceOrderRepository&MockObject $orderRepository;

    private EcommerceOrderItemRepository&MockObject $itemRepository;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EcommerceOrderRepository::class);
        $this->itemRepository  = $this->createMock(EcommerceOrderItemRepository::class);
    }

    public function testAppendsJsonOrdersToLeadRow(): void
    {
        $this->orderRepository->method('getOrdersForLeadTimeline')->with(5)->willReturn([
            'results' => [
                [
                    'id'             => 11,
                    'order_id'       => 'WC-100',
                    'order_total'    => '24.9900',
                    'order_currency' => 'usd',
                    'order_source'   => 'woocommerce',
                    'location'       => 'Hixson',
                    'date_added'     => '2026-09-04 12:00:00',
                    'email_id'       => 3,
                    'email_name'     => 'Welcome',
                ],
            ],
            'total' => 1,
        ]);
        $this->itemRepository->method('getItemsGroupedByOrderIds')->with([11])->willReturn([
            11 => [
                [
                    'item_id'   => 'sku-1',
                    'item_name' => 'Widget',
                    'qty'       => 2,
                    'category'  => 'Gadgets',
                ],
            ],
        ]);

        $lead = new Lead();
        $lead->setId(5);

        $formatter = new ContactOrderExportFormatter($this->orderRepository, $this->itemRepository);
        $export    = $formatter->appendToLeadRow(['email' => 'buyer@example.com'], $lead);

        $this->assertSame('buyer@example.com', $export['email']);
        $orders = json_decode($export[ContactOrderExportFormatter::EXPORT_ALIAS], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'order_id'         => 'WC-100',
                    'order_total'      => '24.99',
                    'order_currency'   => 'USD',
                    'order_source'     => 'woocommerce',
                    'location'         => 'Hixson',
                    'date_added'       => '2026-09-04 12:00:00',
                    'attributed_email' => 'Welcome',
                    'items'            => [
                        [
                            'item_id'   => 'sku-1',
                            'item_name' => 'Widget',
                            'qty'       => 2,
                            'category'  => 'Gadgets',
                        ],
                    ],
                ],
            ],
            $orders
        );
    }

    public function testAppendsOrdersToSingleContactAliasExport(): void
    {
        $this->orderRepository->method('getOrdersForLeadTimeline')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);
        $this->itemRepository->method('getItemsGroupedByOrderIds')->willReturn([]);

        $formatter = new ContactOrderExportFormatter($this->orderRepository, $this->itemRepository);
        $rows      = $formatter->appendToAliasValueExport(
            [
                ['alias' => 'id', 'value' => 8],
                ['alias' => 'email', 'value' => 'buyer@example.com'],
            ],
            'contact_data_buyer@example.com.csv'
        );

        $this->assertSame(
            [
                'alias' => ContactOrderExportFormatter::EXPORT_ALIAS,
                'value' => '[]',
            ],
            $rows[2]
        );
    }

    public function testDoesNotAlterNonContactExports(): void
    {
        $this->orderRepository->expects($this->never())->method('getOrdersForLeadTimeline');

        $formatter = new ContactOrderExportFormatter($this->orderRepository, $this->itemRepository);
        $data      = [['id' => 1, 'name' => 'Campaign']];

        $this->assertSame($data, $formatter->appendToAliasValueExport($data, 'campaigns.csv'));
    }
}
