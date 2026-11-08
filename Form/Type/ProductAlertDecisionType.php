<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ProductAlertSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductInterest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Back in stock, price drop and low inventory: who counts as interested, and the threshold.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ProductAlertDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('interests', ChoiceType::class, [
            'label'       => 'mautic.plugin.ecommerce.campaign.alert.interests',
            'label_attr'  => ['class' => 'control-label'],
            'choices'     => array_combine(
                array_map(static fn (string $type): string => 'mautic.plugin.ecommerce.campaign.alert.interest.'.$type, ProductInterest::TYPES),
                ProductInterest::TYPES
            ),
            'multiple'    => true,
            'expanded'    => true,
            'constraints' => [new Count(['min' => 1, 'minMessage' => 'mautic.plugin.ecommerce.campaign.alert.interests.required'])],
        ]);

        $builder->add('days', IntegerType::class, [
            'label'       => 'mautic.plugin.ecommerce.campaign.alert.days',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'min' => 1, 'max' => ProductInterest::MAX_DAYS, 'tooltip' => 'mautic.plugin.ecommerce.campaign.alert.days.tooltip'],
            'constraints' => [new Range(['min' => 1, 'max' => ProductInterest::MAX_DAYS])],
        ]);

        // A single view is often just browsing; only applies when "viewed" is ticked.
        $builder->add('min_views', IntegerType::class, [
            'label'       => 'mautic.plugin.ecommerce.campaign.alert.min_views',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'empty_data'  => '1',
            'attr'        => ['class' => 'form-control', 'min' => 1, 'max' => 50, 'placeholder' => '1', 'tooltip' => 'mautic.plugin.ecommerce.campaign.alert.min_views.tooltip'],
            'constraints' => [new Range(['min' => 1, 'max' => 50])],
        ]);

        if (ProductAlertSubscriber::DECISION_PRICE_DROP === $options['alert']) {
            $builder->add('min_drop_percent', NumberType::class, [
                'label'       => 'mautic.plugin.ecommerce.campaign.alert.min_drop_percent',
                'label_attr'  => ['class' => 'control-label'],
                'scale'       => 1,
                'attr'        => ['class' => 'form-control', 'tooltip' => 'mautic.plugin.ecommerce.campaign.alert.min_drop_percent.tooltip'],
                'constraints' => [new Range(['min' => 0, 'max' => 100])],
            ]);
        }

        if (ProductAlertSubscriber::DECISION_LOW_STOCK === $options['alert']) {
            $builder->add('threshold', IntegerType::class, [
                'label'       => 'mautic.plugin.ecommerce.campaign.alert.threshold',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => ['class' => 'form-control', 'min' => 1, 'tooltip' => 'mautic.plugin.ecommerce.campaign.alert.threshold.tooltip'],
                'constraints' => [new Range(['min' => 1])],
            ]);
        }

        foreach (['categories', 'products'] as $field) {
            $builder->add($field, TextType::class, [
                'label'      => 'mautic.plugin.ecommerce.campaign.storefront_'.$field,
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => ['class' => 'form-control', 'tooltip' => 'mautic.plugin.ecommerce.campaign.storefront_'.$field.'.tooltip'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['alert' => null]);
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_product_alert';
    }
}
