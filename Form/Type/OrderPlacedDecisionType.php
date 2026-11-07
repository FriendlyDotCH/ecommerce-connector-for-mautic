<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class OrderPlacedDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('min_total', NumberType::class, [
            'label'      => 'mautic.plugin.ecommerce.campaign.min_total',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'scale'      => 2,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.ecommerce.campaign.min_total.tooltip',
            ],
        ]);

        foreach (['categories', 'products', 'sources'] as $field) {
            $builder->add($field, TextType::class, [
                'label'      => 'mautic.plugin.ecommerce.campaign.'.$field,
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.ecommerce.campaign.'.$field.'.tooltip',
                ],
            ]);
        }
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_order_placed';
    }
}
