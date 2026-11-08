<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponIssuer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The "Issue coupon" action: what the coupon gives and how long it is valid.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class CouponActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('discount_type', ChoiceType::class, [
            'label'      => 'mautic.plugin.ecommerce.coupon.discount_type',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => [
                'mautic.plugin.ecommerce.coupon.discount_type.percent'    => CouponIssuer::TYPE_PERCENT,
                'mautic.plugin.ecommerce.coupon.discount_type.fixed_cart' => CouponIssuer::TYPE_FIXED_CART,
            ],
            'attr'       => ['class' => 'form-control'],
        ]);

        $builder->add('amount', NumberType::class, [
            'label'       => 'mautic.plugin.ecommerce.coupon.amount',
            'label_attr'  => ['class' => 'control-label'],
            'scale'       => 2,
            'attr'        => ['class' => 'form-control', 'tooltip' => 'mautic.plugin.ecommerce.coupon.amount.tooltip'],
            'constraints' => [new NotBlank(), new Range(['min' => 0.01, 'max' => 100000])],
        ]);

        $builder->add('valid_days', IntegerType::class, [
            'label'       => 'mautic.plugin.ecommerce.coupon.valid_days',
            'label_attr'  => ['class' => 'control-label'],
            'empty_data'  => (string) CouponIssuer::DEFAULT_VALID_DAYS,
            'attr'        => ['class' => 'form-control', 'min' => 1, 'max' => 365],
            'constraints' => [new Range(['min' => 1, 'max' => 365])],
        ]);

        $builder->add('minimum_amount', NumberType::class, [
            'label'       => 'mautic.plugin.ecommerce.coupon.minimum_amount',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'scale'       => 2,
            'attr'        => ['class' => 'form-control', 'tooltip' => 'mautic.plugin.ecommerce.coupon.minimum_amount.tooltip'],
            'constraints' => [new Range(['min' => 0])],
        ]);

        $builder->add('prefix', TextType::class, [
            'label'       => 'mautic.plugin.ecommerce.coupon.prefix',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'attr'        => ['class' => 'form-control', 'placeholder' => 'WELCOME', 'maxlength' => 12, 'tooltip' => 'mautic.plugin.ecommerce.coupon.prefix.tooltip'],
            'constraints' => [new Regex(['pattern' => '/^[A-Za-z0-9]{0,12}$/', 'message' => 'mautic.plugin.ecommerce.coupon.prefix.invalid'])],
        ]);

        $builder->add('exclude_sale_items', YesNoButtonGroupType::class, [
            'label' => 'mautic.plugin.ecommerce.coupon.exclude_sale_items',
            'data'  => (bool) ($options['data']['exclude_sale_items'] ?? false),
        ]);

        $builder->add('free_shipping', YesNoButtonGroupType::class, [
            'label' => 'mautic.plugin.ecommerce.coupon.free_shipping',
            'data'  => (bool) ($options['data']['free_shipping'] ?? false),
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_coupon';
    }
}
