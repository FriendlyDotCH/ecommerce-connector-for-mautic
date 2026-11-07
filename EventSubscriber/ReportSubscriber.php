<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ReportSubscriber implements EventSubscriberInterface
{
    public const CONTEXT_ECOMMERCE_ORDERS = 'ecommerce_orders';

    public const CONTEXT_ECOMMERCE_ORDER_ITEMS = 'ecommerce_order_items';

    public const PREFIX_ITEM = 'ei';

    public const PREFIX_ORDER = 'eo';

    public const PREFIX_EMAIL = 'e';

    public const PREFIX_LEAD  = 'l';

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD     => ['onReportBuilder', 0],
            ReportEvents::REPORT_ON_GENERATE  => ['onReportGenerate', 0],
        ];
    }

    public function onReportBuilder(ReportBuilderEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT_ECOMMERCE_ORDERS, self::CONTEXT_ECOMMERCE_ORDER_ITEMS])) {
            return;
        }

        $columns = [
            self::PREFIX_ORDER.'.order_id' => [
                'label' => 'mautic.plugin.ecommerce.report.order_id',
                'type'  => 'string',
            ],
            self::PREFIX_ORDER.'.order_total' => [
                'label' => 'mautic.plugin.ecommerce.report.order_total',
                'type'  => 'float',
            ],
            self::PREFIX_ORDER.'.status' => [
                'label' => 'mautic.plugin.ecommerce.status',
                'type'  => 'string',
            ],
            self::PREFIX_ORDER.'.refunded_total' => [
                'label' => 'mautic.plugin.ecommerce.refunded_total',
                'type'  => 'float',
            ],
            self::PREFIX_ORDER.'.net_total' => [
                'label' => 'mautic.plugin.ecommerce.net_total',
                'type'  => 'float',
            ],
            self::PREFIX_ORDER.'.order_currency' => [
                'label' => 'mautic.plugin.ecommerce.report.order_currency',
                'type'  => 'string',
            ],
            self::PREFIX_ORDER.'.order_source' => [
                'label' => 'mautic.plugin.ecommerce.report.order_source',
                'type'  => 'string',
            ],
            self::PREFIX_ORDER.'.order_date' => [
                'label' => 'mautic.plugin.ecommerce.report.order_date',
                'type'  => 'datetime',
            ],
            self::PREFIX_ORDER.'.date_added' => [
                'label' => 'mautic.plugin.ecommerce.report.date_added',
                'type'  => 'datetime',
            ],
            self::PREFIX_EMAIL.'.subject' => [
                'label' => 'mautic.email.subject',
                'type'  => 'string',
            ],
            self::PREFIX_LEAD.'.email' => [
                'label' => 'mautic.lead.email',
                'type'  => 'string',
            ],
        ];

        if ($event->checkContext(self::CONTEXT_ECOMMERCE_ORDERS)) {
            $event->addTable(
                self::CONTEXT_ECOMMERCE_ORDERS,
                [
                    'display_name' => 'mautic.plugin.ecommerce.report.table',
                    'columns'      => $columns,
                    'filters'      => $columns,
                ],
                'ecommerce'
            );
        }

        if ($event->checkContext(self::CONTEXT_ECOMMERCE_ORDER_ITEMS)) {
            // One row per line item, with its order's columns alongside.
            $itemColumns = [
                self::PREFIX_ITEM.'.item_id' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_id',
                    'type'  => 'string',
                ],
                self::PREFIX_ITEM.'.item_name' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_name',
                    'type'  => 'string',
                ],
                self::PREFIX_ITEM.'.category' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_category',
                    'type'  => 'string',
                ],
                self::PREFIX_ITEM.'.qty' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_qty',
                    'type'  => 'int',
                ],
                self::PREFIX_ITEM.'.refunded_qty' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_refunded_qty',
                    'type'  => 'int',
                ],
                self::PREFIX_ITEM.'.unit_price' => [
                    'label' => 'mautic.plugin.ecommerce.tab.item_unit_price',
                    'type'  => 'float',
                ],
                'line_total' => [
                    'label'   => 'mautic.plugin.ecommerce.report.line_total',
                    'type'    => 'float',
                    // Net of refunded quantities.
                    'formula' => '('.self::PREFIX_ITEM.'.qty - '.self::PREFIX_ITEM.'.refunded_qty) * '.self::PREFIX_ITEM.'.unit_price',
                ],
            ] + $columns;

            $event->addTable(
                self::CONTEXT_ECOMMERCE_ORDER_ITEMS,
                [
                    'display_name' => 'mautic.plugin.ecommerce.report.items_table',
                    'columns'      => $itemColumns,
                    'filters'      => $itemColumns,
                ],
                'ecommerce'
            );
        }
    }

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT_ECOMMERCE_ORDERS, self::CONTEXT_ECOMMERCE_ORDER_ITEMS])) {
            return;
        }

        $queryBuilder = $event->getQueryBuilder();

        if ($event->checkContext(self::CONTEXT_ECOMMERCE_ORDER_ITEMS)) {
            $queryBuilder->from(MAUTIC_TABLE_PREFIX.'ecommerce_order_items', self::PREFIX_ITEM)
                ->innerJoin(self::PREFIX_ITEM, MAUTIC_TABLE_PREFIX.'ecommerce_orders', self::PREFIX_ORDER, self::PREFIX_ORDER.'.id = '.self::PREFIX_ITEM.'.order_id')
                ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'emails', self::PREFIX_EMAIL, self::PREFIX_EMAIL.'.id = '.self::PREFIX_ORDER.'.email_id')
                ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'leads', self::PREFIX_LEAD, self::PREFIX_LEAD.'.id = '.self::PREFIX_ORDER.'.lead_id');

            return;
        }

        $queryBuilder->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', self::PREFIX_ORDER)
            ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'emails', self::PREFIX_EMAIL, self::PREFIX_EMAIL.'.id = '.self::PREFIX_ORDER.'.email_id')
            ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'leads', self::PREFIX_LEAD, self::PREFIX_LEAD.'.id = '.self::PREFIX_ORDER.'.lead_id');
    }
}
