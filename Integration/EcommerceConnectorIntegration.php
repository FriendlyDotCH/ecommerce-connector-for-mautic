<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
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
        if ('keys' === $formArea) {
            // Optional, so not a required key field; saved and encrypted with the keys.
            $builder->add('shopify_webhook_secret', TextType::class, [
                'label'      => 'mautic.plugin.ecommerce.shopify_webhook_secret',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'        => 'form-control',
                    'autocomplete' => 'off',
                    'tooltip'      => 'mautic.plugin.ecommerce.shopify_webhook_secret.tooltip',
                ],
            ]);

            $builder->add('coupon_url', UrlType::class, [
                'label'            => 'mautic.plugin.ecommerce.coupon_url',
                'label_attr'       => ['class' => 'control-label'],
                'required'         => false,
                'default_protocol' => 'https',
                'attr'             => [
                    'class'       => 'form-control',
                    'placeholder' => 'https://shop.example/wp-json/mautic-ecommerce/v1/coupon',
                    'tooltip'     => 'mautic.plugin.ecommerce.coupon_url.tooltip',
                ],
            ]);

            return;
        }

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
            'money_locale',
            TextType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.money_locale',
                'required' => false,
                'data'     => $data['money_locale'] ?? '',
                'attr'     => [
                    'tooltip'     => 'mautic.plugin.ecommerce.money_locale.tooltip',
                    'placeholder' => 'hu_HU',
                ],
            ]
        );

        $builder->add(
            'base_currency',
            TextType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.base_currency',
                'required' => false,
                'data'     => $data['base_currency'] ?? '',
                'attr'     => [
                    'tooltip'     => 'mautic.plugin.ecommerce.base_currency.tooltip',
                    'placeholder' => 'HUF',
                ],
            ]
        );

        $builder->add(
            'exchange_rates',
            TextType::class,
            [
                'label'    => 'mautic.plugin.ecommerce.exchange_rates',
                'required' => false,
                'data'     => $data['exchange_rates'] ?? '',
                'attr'     => [
                    'tooltip'     => 'mautic.plugin.ecommerce.exchange_rates.tooltip',
                    'placeholder' => 'EUR=395, USD=360',
                ],
            ]
        );

        $builder->add(
            'exchange_rates_ecb',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.plugin.ecommerce.exchange_rates_ecb',
                'data'  => isset($data['exchange_rates_ecb']) && (bool) $data['exchange_rates_ecb'],
                'attr'  => [
                    'tooltip' => 'mautic.plugin.ecommerce.exchange_rates_ecb.tooltip',
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

        $builder->add(
            'on_contact_delete',
            ChoiceType::class,
            [
                'label'      => 'mautic.plugin.ecommerce.on_contact_delete',
                'label_attr' => ['class' => 'control-label'],
                'choices'    => [
                    'mautic.plugin.ecommerce.on_contact_delete.delete'    => Config::ON_CONTACT_DELETE_DELETE,
                    'mautic.plugin.ecommerce.on_contact_delete.anonymize' => Config::ON_CONTACT_DELETE_ANONYMIZE,
                ],
                'data'       => $data['on_contact_delete'] ?? Config::ON_CONTACT_DELETE_DELETE,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.ecommerce.on_contact_delete.tooltip',
                ],
            ]
        );

        // Storefront events.
        $builder->add(
            'create_cross_sell_segments',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.plugin.ecommerce.create_cross_sell_segments',
                'data'  => isset($data['create_cross_sell_segments']) && (bool) $data['create_cross_sell_segments'],
                'attr'  => ['tooltip' => 'mautic.plugin.ecommerce.create_cross_sell_segments.tooltip'],
            ]
        );

        $builder->add(
            'enable_storefront_events',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.plugin.ecommerce.enable_storefront_events',
                'data'  => isset($data['enable_storefront_events']) && (bool) $data['enable_storefront_events'],
                'attr'  => ['tooltip' => 'mautic.plugin.ecommerce.enable_storefront_events.tooltip'],
            ]
        );

        foreach ([
            'event_retention_days'   => Config::DEFAULT_EVENT_RETENTION_DAYS,
            'cart_abandon_minutes'   => Config::DEFAULT_CART_ABANDON_MINUTES,
            'browse_abandon_minutes' => Config::DEFAULT_BROWSE_ABANDON_MINUTES,
            'alert_collect_minutes'  => Config::DEFAULT_ALERT_COLLECT_MINUTES,
            'alert_cap_hours'        => Config::DEFAULT_ALERT_CAP_HOURS,
        ] as $key => $default) {
            $builder->add(
                $key,
                NumberType::class,
                [
                    'label'    => 'mautic.plugin.ecommerce.'.$key,
                    'required' => false,
                    'scale'    => 0,
                    'data'     => isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : $default,
                    'attr'     => ['tooltip' => 'mautic.plugin.ecommerce.'.$key.'.tooltip'],
                ]
            );
        }

        // Lifecycle stage thresholds.
        foreach (Config::LIFECYCLE_DEFAULTS as $key => $default) {
            $builder->add(
                $key,
                NumberType::class,
                [
                    'label'    => 'mautic.plugin.ecommerce.'.$key,
                    'required' => false,
                    'scale'    => is_float($default) ? 1 : 0,
                    'data'     => isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : $default,
                    'attr'     => [
                        'tooltip' => 'mautic.plugin.ecommerce.'.$key.'.tooltip',
                    ],
                ]
            );
        }
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
