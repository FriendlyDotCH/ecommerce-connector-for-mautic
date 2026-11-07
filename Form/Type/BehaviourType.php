<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<EcommerceBehaviour>
 */
class BehaviourType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EcommerceBehaviour|null $behaviour */
        $behaviour = $options['data'] ?? null;
        $isNew     = null === $behaviour?->getId();

        $builder->add('name', TextType::class, [
            'label'      => 'mautic.core.name',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
        ]);

        // The field alias is fixed once the contact field exists.
        $builder->add('alias', TextType::class, [
            'label'       => 'mautic.plugin.ecommerce.behaviour.alias',
            'label_attr'  => ['class' => 'control-label'],
            'mapped'      => false,
            'required'    => false,
            'disabled'    => !$isNew,
            'data'        => $behaviour?->getFieldAlias(),
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.ecommerce.behaviour.alias.tooltip',
            ],
            'constraints' => $isNew ? [new Regex(['pattern' => '/^[a-zA-Z0-9_ ]*$/'])] : [],
        ]);

        $metricChoices = [];
        foreach (PurchaseCriteria::METRICS as $metric) {
            $metricChoices['mautic.plugin.ecommerce.purchase.metric.'.$metric] = $metric;
        }

        $builder->add('metric', ChoiceType::class, [
            'label'      => 'mautic.plugin.ecommerce.purchase.metric',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => $metricChoices,
            'attr'       => ['class' => 'form-control'],
        ]);

        PurchaseHistoryConditionType::addScopeFields($builder, $this->getBlockPrefix().'_');

        $builder->add('isPublished', YesNoButtonGroupType::class, [
            'label' => 'mautic.core.form.ispublished',
        ]);

        $builder->add('buttons', FormButtonsType::class);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EcommerceBehaviour::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ecommerce_behaviour';
    }
}
