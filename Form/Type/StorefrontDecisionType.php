<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Filters for the viewed product, cart, checkout and abandonment decisions.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class StorefrontDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('min_value', NumberType::class, [
            'label'      => 'mautic.plugin.ecommerce.campaign.min_value',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'scale'      => 2,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.ecommerce.campaign.min_value.tooltip',
            ],
        ]);

        foreach (['categories', 'products'] as $field) {
            $builder->add($field, TextType::class, [
                'label'      => 'mautic.plugin.ecommerce.campaign.storefront_'.$field,
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.ecommerce.campaign.storefront_'.$field.'.tooltip',
                ],
            ]);
        }
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_storefront';
    }
}
