<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class NoOrderWithinConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('hours', IntegerType::class, [
            'label'       => 'mautic.plugin.ecommerce.campaign.no_order_hours',
            'label_attr'  => ['class' => 'control-label'],
            'data'        => $options['data']['hours'] ?? 24,
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.ecommerce.campaign.no_order_hours.tooltip',
            ],
            'constraints' => [new NotBlank(), new GreaterThan(0)],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'campaignevent_ecommerce_no_order_within';
    }
}
