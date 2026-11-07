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

    public const LIFECYCLE_STAGE = 'ecommerce_lifecycle_stage';

    public const RFM_SCORE = 'ecommerce_rfm_score';

    public const RFM_RECENCY = 'ecommerce_rfm_recency';

    public const RFM_FREQUENCY = 'ecommerce_rfm_frequency';

    public const RFM_MONETARY = 'ecommerce_rfm_monetary';

    public const STAGE_NEW     = 'new';
    public const STAGE_ACTIVE  = 'active';
    public const STAGE_VIP     = 'vip';
    public const STAGE_AT_RISK = 'at_risk';
    public const STAGE_LAPSED  = 'lapsed';

    public const STAGES = [self::STAGE_NEW, self::STAGE_ACTIVE, self::STAGE_VIP, self::STAGE_AT_RISK, self::STAGE_LAPSED];

    /**
     * Select fields carry a "list" of option values; their labels are translation keys.
     *
     * @return array<string, array{label: string, type: string, alias: string, list?: array<string, string>}>
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
            self::LIFECYCLE_STAGE => [
                'label' => 'mautic.plugin.ecommerce.field.lifecycle_stage',
                'type'  => 'select',
                'alias' => self::LIFECYCLE_STAGE,
                'list'  => array_combine(
                    self::STAGES,
                    array_map(static fn (string $stage): string => 'mautic.plugin.ecommerce.stage.'.$stage, self::STAGES)
                ),
            ],
            self::RFM_SCORE => [
                'label' => 'mautic.plugin.ecommerce.field.rfm_score',
                'type'  => 'text',
                'alias' => self::RFM_SCORE,
            ],
            self::RFM_RECENCY => [
                'label' => 'mautic.plugin.ecommerce.field.rfm_recency',
                'type'  => 'number',
                'alias' => self::RFM_RECENCY,
            ],
            self::RFM_FREQUENCY => [
                'label' => 'mautic.plugin.ecommerce.field.rfm_frequency',
                'type'  => 'number',
                'alias' => self::RFM_FREQUENCY,
            ],
            self::RFM_MONETARY => [
                'label' => 'mautic.plugin.ecommerce.field.rfm_monetary',
                'type'  => 'number',
                'alias' => self::RFM_MONETARY,
            ],
        ];
    }
}
