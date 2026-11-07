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
        if (!$event->checkContext([self::CONTEXT_ECOMMERCE_ORDERS])) {
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

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT_ECOMMERCE_ORDERS])) {
            return;
        }

        $queryBuilder = $event->getQueryBuilder();
        $queryBuilder->from(MAUTIC_TABLE_PREFIX.'ecommerce_orders', self::PREFIX_ORDER)
            ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'emails', self::PREFIX_EMAIL, self::PREFIX_EMAIL.'.id = '.self::PREFIX_ORDER.'.email_id')
            ->leftJoin(self::PREFIX_ORDER, MAUTIC_TABLE_PREFIX.'leads', self::PREFIX_LEAD, self::PREFIX_LEAD.'.id = '.self::PREFIX_ORDER.'.lead_id');
    }
}
