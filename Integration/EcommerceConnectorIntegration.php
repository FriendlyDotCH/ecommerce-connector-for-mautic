<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EcommerceConnectorIntegration extends AbstractIntegration
{
    public const NAME = 'EcommerceConnector';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getFormTheme(): string
    {
        return '@EcommerceConnector/FormTheme/Integration/layout.html.twig';
    }

    public function getFormTemplate(): string
    {
        return '@EcommerceConnector/Integration/config_form.html.twig';
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [
            'webhook_secret' => 'mautic.plugin.ecommerce.webhook_secret',
        ];
    }

    /**
     * @param FormBuilder|Form $builder
     * @param mixed[]          $data
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $builder->add(
            'enable_page_hit_tracking',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.plugin.ecommerce.enable_page_hit_tracking',
                'data'  => array_key_exists('enable_page_hit_tracking', $data)
                    ? (bool) $data['enable_page_hit_tracking']
                    : Config::DEFAULT_ENABLE_PAGE_HIT_TRACKING,
                'attr'  => [
                    'tooltip' => 'mautic.plugin.ecommerce.enable_page_hit_tracking.tooltip',
                ],
            ]
        );

        $builder->add(
            'attribute_last_email',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.plugin.ecommerce.attribute_last_email',
                'data'  => isset($data['attribute_last_email']) && (bool) $data['attribute_last_email'],
                'attr'  => [
                    'tooltip' => 'mautic.plugin.ecommerce.attribute_last_email.tooltip',
                ],
            ]
        );

        $builder->add(
            'allowed_order_sources',
            TextType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.allowed_order_sources',
                'required' => false,
                'data'     => $data['allowed_order_sources'] ?? '',
                'attr'     => [
                    'tooltip'     => 'mautic.plugin.ecommerce.allowed_order_sources.tooltip',
                    'placeholder' => 'web,woocommerce,shopify',
                ],
            ]
        );

        $builder->add(
            'default_currency',
            TextType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.default_currency',
                'required' => false,
                'data'     => $data['default_currency'] ?? Config::DEFAULT_CURRENCY,
                'attr'     => [
                    'tooltip' => 'mautic.plugin.ecommerce.default_currency.tooltip',
                ],
            ]
        );

        $builder->add(
            'max_order_total',
            NumberType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.max_order_total',
                'required' => false,
                'scale'    => 2,
                'data'     => $data['max_order_total'] ?? Config::DEFAULT_MAX_TOTAL,
                'attr'     => [
                    'tooltip' => 'mautic.plugin.ecommerce.max_order_total.tooltip',
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>|string
     */
    public function getFormNotes($section)
    {
        if ('custom' === $section) {
            return [
                'custom'     => true,
                'template'   => '@EcommerceConnector/Integration/form.html.twig',
                'parameters' => [
                    'webhookUrl' => $this->router->generate(
                        'mautic_ecommerce_track',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ],
            ];
        }

        return parent::getFormNotes($section);
    }

    /**
     * @param mixed[] $config
     *
     * @return mixed[]
     */
    public function mergeConfigToFeatureSettings($config = [])
    {
        $featureSettings = $this->settings->getFeatureSettings();
        $privacyNotice   = $featureSettings[PrivacyUpgradeNotice::FEATURE_KEY] ?? null;

        if (empty($config['integration']) || $config['integration'] === $this->getName()) {
            $featureSettings = array_merge($featureSettings, $config['config'] ?? []);
        }

        if (is_string($privacyNotice)) {
            $featureSettings[PrivacyUpgradeNotice::FEATURE_KEY] = $privacyNotice;
        }

        if (!array_key_exists('enable_page_hit_tracking', $featureSettings)) {
            $featureSettings['enable_page_hit_tracking'] = Config::DEFAULT_ENABLE_PAGE_HIT_TRACKING;
        }

        return $featureSettings;
    }
}
