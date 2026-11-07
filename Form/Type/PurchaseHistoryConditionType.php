<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CampaignSubscriber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class PurchaseHistoryConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('metric', ChoiceType::class, [
            'label'      => 'mautic.plugin.ecommerce.purchase.metric',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => self::choices(PurchaseCriteria::METRICS, 'mautic.plugin.ecommerce.purchase.metric.'),
            'attr'       => ['class' => 'form-control'],
        ]);

        $builder->add('operator', ChoiceType::class, [
            'label'      => 'mautic.plugin.ecommerce.campaign.operator',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => [
                'mautic.lead.list.form.operator.greaterthanequals' => 'gte',
                'mautic.lead.list.form.operator.greaterthan'       => 'gt',
                'mautic.lead.list.form.operator.lessthanequals'    => 'lte',
                'mautic.lead.list.form.operator.lessthan'          => 'lt',
                'mautic.lead.list.form.operator.equals'            => 'eq',
            ],
            'attr'       => ['class' => 'form-control'],
        ]);

        $builder->add('value', NumberType::class, [
            'label'       => 'mautic.plugin.ecommerce.campaign.value',
            'label_attr'  => ['class' => 'control-label'],
            'scale'       => 2,
            'attr'        => ['class' => 'form-control'],
            'constraints' => [new NotBlank(), new GreaterThanOrEqual(0)],
        ]);

        self::addScopeFields($builder, 'campaignevent_properties_');
    }

    /**
     * Window, scope and values fields shared with the behaviour form.
     *
     * @param string $idPrefix HTML ID prefix of the fields, used to show values only for a narrowed scope
     */
    public static function addScopeFields(FormBuilderInterface $builder, string $idPrefix): void
    {
        $builder->add('days', IntegerType::class, [
            'label'       => 'mautic.plugin.ecommerce.purchase.days',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'empty_data'  => '0',
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.ecommerce.purchase.days.tooltip',
            ],
            'constraints' => [new GreaterThanOrEqual(0)],
        ]);

        $builder->add('scope', ChoiceType::class, [
            'label'      => 'mautic.plugin.ecommerce.purchase.scope',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => self::choices(PurchaseCriteria::SCOPES, 'mautic.plugin.ecommerce.purchase.scope.'),
            'attr'       => ['class' => 'form-control'],
        ]);

        $builder->add('values', TextType::class, [
            'label'      => 'mautic.plugin.ecommerce.purchase.values',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => [
                'class'        => 'form-control',
                'tooltip'      => 'mautic.plugin.ecommerce.purchase.values.tooltip',
                'data-show-on' => '{"'.$idPrefix.'scope":["category","product","source"]}',
            ],
        ]);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function choices(array $keys, string $labelPrefix): array
    {
        $choices = [];
        foreach ($keys as $key) {
            $choices[$labelPrefix.$key] = $key;
        }

        return $choices;
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_purchase_history';
    }
}
