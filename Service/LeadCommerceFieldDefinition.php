<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

final class LeadCommerceFieldDefinition
{
    public const GROUP = 'ecommerce';

    public const LIFETIME_VALUE = 'ecommerce_lifetime_value';

    public const ORDER_COUNT = 'ecommerce_order_count';

    public const LAST_ORDER_DATE = 'ecommerce_last_order_date';

    public const LAST_ORDER_TOTAL = 'ecommerce_last_order_total';

    public const DAYS_SINCE_LAST_ORDER = 'ecommerce_days_since_last_order';

    public const AVG_DAYS_BETWEEN_ORDERS = 'ecommerce_avg_days_between_orders';

    public const NEXT_ORDER_DATE = 'ecommerce_next_order_date';

    /**
     * @return array<string, array{label: string, type: string, alias: string}>
     */
    public static function getFields(): array
    {
        return [
            self::LIFETIME_VALUE => [
                'label' => 'mautic.plugin.ecommerce.field.lifetime_value',
                'type'  => 'number',
                'alias' => self::LIFETIME_VALUE,
            ],
            self::ORDER_COUNT => [
                'label' => 'mautic.plugin.ecommerce.field.order_count',
                'type'  => 'number',
                'alias' => self::ORDER_COUNT,
            ],
            self::LAST_ORDER_DATE => [
                'label' => 'mautic.plugin.ecommerce.field.last_order_date',
                'type'  => 'datetime',
                'alias' => self::LAST_ORDER_DATE,
            ],
            self::LAST_ORDER_TOTAL => [
                'label' => 'mautic.plugin.ecommerce.field.last_order_total',
                'type'  => 'number',
                'alias' => self::LAST_ORDER_TOTAL,
            ],
            self::DAYS_SINCE_LAST_ORDER => [
                'label' => 'mautic.plugin.ecommerce.field.days_since_last_order',
                'type'  => 'number',
                'alias' => self::DAYS_SINCE_LAST_ORDER,
            ],
            self::AVG_DAYS_BETWEEN_ORDERS => [
                'label' => 'mautic.plugin.ecommerce.field.avg_days_between_orders',
                'type'  => 'number',
                'alias' => self::AVG_DAYS_BETWEEN_ORDERS,
            ],
            self::NEXT_ORDER_DATE => [
                'label' => 'mautic.plugin.ecommerce.field.next_order_date',
                'type'  => 'date',
                'alias' => self::NEXT_ORDER_DATE,
            ],
        ];
    }
}
