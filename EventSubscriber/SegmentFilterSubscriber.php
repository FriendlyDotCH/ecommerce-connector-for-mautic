<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\Event\LeadListFiltersDecoratorDelegateEvent;
use Mautic\LeadBundle\Event\SegmentDictionaryGenerationEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Provider\FieldChoicesProviderInterface;
use Mautic\LeadBundle\Provider\TypeOperatorProviderInterface;
use Mautic\LeadBundle\Segment\OperatorOptions;
use Mautic\LeadBundle\Segment\Query\Filter\ForeignFuncFilterQueryBuilder;
use Mautic\LeadBundle\Segment\Query\Filter\ForeignValueFilterQueryBuilder;
use MauticPlugin\EcommerceConnectorBundle\Segment\HasOrderFilterDecorator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SegmentFilterSubscriber implements EventSubscriberInterface
{
    public const FILTER_HAS_ORDER      = 'ecommerce_has_order';
    public const FILTER_ORDER_TOTAL    = 'ecommerce_order_total';
    public const FILTER_ORDER_DATE     = 'ecommerce_order_date';
    public const FILTER_ORDER_SOURCE   = 'ecommerce_order_source';
    public const FILTER_PRODUCT_ID     = 'ecommerce_product_id';
    public const FILTER_PRODUCT_NAME   = 'ecommerce_product_name';
    public const FILTER_PRODUCT_CATEGORY = 'ecommerce_product_category';

    public function __construct(
        private TypeOperatorProviderInterface $typeOperatorProvider,
        private FieldChoicesProviderInterface $fieldChoicesProvider,
        private HasOrderFilterDecorator $hasOrderFilterDecorator,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onGenerateSegmentFilters', 0],
            LeadEvents::SEGMENT_DICTIONARY_ON_GENERATE   => ['onSegmentDictionaryGenerate', 0],
            LeadEvents::SEGMENT_ON_DECORATOR_DELEGATE    => ['onDecoratorDelegate', 0],
        ];
    }

    public function onGenerateSegmentFilters(LeadListFiltersChoicesEvent $event): void
    {
        if (!$event->isForSegmentation()) {
            return;
        }

        $textOperators = $this->typeOperatorProvider->getOperatorsIncluding([
            OperatorOptions::EQUAL_TO,
            OperatorOptions::NOT_EQUAL_TO,
            OperatorOptions::LIKE,
            OperatorOptions::NOT_LIKE,
            OperatorOptions::STARTS_WITH,
            OperatorOptions::ENDS_WITH,
            OperatorOptions::CONTAINS,
            OperatorOptions::EMPTY,
            OperatorOptions::NOT_EMPTY,
        ]);

        $choices = [
            self::FILTER_HAS_ORDER => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.has_order'),
                'properties' => [
                    'type' => 'boolean',
                    'list' => $this->fieldChoicesProvider->getChoicesForField('boolean', self::FILTER_HAS_ORDER),
                ],
                'operators' => $this->typeOperatorProvider->getOperatorsForFieldType('bool'),
                'object'    => 'lead',
            ],
            self::FILTER_ORDER_TOTAL => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.order_total'),
                'properties' => ['type' => 'number'],
                'operators'  => $this->typeOperatorProvider->getOperatorsIncluding([
                    OperatorOptions::GREATER_THAN,
                    OperatorOptions::GREATER_THAN_OR_EQUAL,
                    OperatorOptions::EQUAL_TO,
                    OperatorOptions::NOT_EQUAL_TO,
                    OperatorOptions::LESS_THAN,
                    OperatorOptions::LESS_THAN_OR_EQUAL,
                ]),
                'object' => 'lead',
            ],
            self::FILTER_ORDER_DATE => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.order_date'),
                'properties' => ['type' => 'datetime'],
                'operators'  => $this->typeOperatorProvider->getOperatorsIncluding([
                    OperatorOptions::EQUAL_TO,
                    OperatorOptions::NOT_EQUAL_TO,
                    OperatorOptions::GREATER_THAN,
                    OperatorOptions::LESS_THAN,
                    OperatorOptions::GREATER_THAN_OR_EQUAL,
                    OperatorOptions::LESS_THAN_OR_EQUAL,
                ]),
                'object' => 'lead',
            ],
            self::FILTER_ORDER_SOURCE => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.order_source'),
                'properties' => ['type' => 'text'],
                'operators'  => $textOperators,
                'object'     => 'lead',
            ],
            self::FILTER_PRODUCT_ID => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.product_id'),
                'properties' => ['type' => 'text'],
                'operators'  => $textOperators,
                'object'     => 'lead',
            ],
            self::FILTER_PRODUCT_NAME => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.product_name'),
                'properties' => ['type' => 'text'],
                'operators'  => $textOperators,
                'object'     => 'lead',
            ],
            self::FILTER_PRODUCT_CATEGORY => [
                'label'      => $this->translator->trans('mautic.plugin.ecommerce.segment.product_category'),
                'properties' => ['type' => 'text'],
                'operators'  => $textOperators,
                'object'     => 'lead',
            ],
        ];

        foreach ($choices as $alias => $fieldOptions) {
            $event->addChoice('ecommerce', $alias, $fieldOptions);
        }
    }

    public function onSegmentDictionaryGenerate(SegmentDictionaryGenerationEvent $event): void
    {
        $event->addTranslation(self::FILTER_HAS_ORDER, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_orders',
            'foreign_table_field' => 'lead_id',
            'field'               => 'id',
        ]);

        $event->addTranslation(self::FILTER_ORDER_TOTAL, [
            'type'                => ForeignFuncFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_orders',
            'foreign_table_field' => 'lead_id',
            'table'               => 'leads',
            'table_field'         => 'id',
            'func'                => 'max',
            'field'               => 'order_total',
            'null_value'          => 0,
        ]);

        $event->addTranslation(self::FILTER_ORDER_DATE, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_orders',
            'foreign_table_field' => 'lead_id',
            'field'               => 'date_added',
        ]);

        $event->addTranslation(self::FILTER_ORDER_SOURCE, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_orders',
            'foreign_table_field' => 'lead_id',
            'field'               => 'order_source',
        ]);

        $event->addTranslation(self::FILTER_PRODUCT_ID, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_order_items',
            'foreign_table_field' => 'lead_id',
            'field'               => 'item_id',
        ]);

        $event->addTranslation(self::FILTER_PRODUCT_NAME, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_order_items',
            'foreign_table_field' => 'lead_id',
            'field'               => 'item_name',
        ]);

        $event->addTranslation(self::FILTER_PRODUCT_CATEGORY, [
            'type'                => ForeignValueFilterQueryBuilder::getServiceId(),
            'foreign_table'       => 'ecommerce_order_items',
            'foreign_table_field' => 'lead_id',
            'field'               => 'category',
        ]);
    }

    public function onDecoratorDelegate(LeadListFiltersDecoratorDelegateEvent $event): void
    {
        if (self::FILTER_HAS_ORDER !== $event->getCrate()->getField()) {
            return;
        }

        $event->setDecorator($this->hasOrderFilterDecorator);
    }
}
