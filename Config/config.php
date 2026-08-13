<?php

return [
    'name'        => 'Ecommerce Connector',
    'description' => 'Track eCommerce orders from your store and attribute revenue to Mautic emails. Configure the webhook secret and endpoint under Plugins > Ecommerce Connector.',
    'version'     => '1.3.0',
    'author'      => 'Dog Byte Marketing',
    'routes'      => [
        'main' => [
            'mautic_ecommerce_order_action' => [
                'path'       => '/ecommerce/order/{leadId}/{objectId}',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\OrderController::viewAction',
                'method'     => 'GET',
                'requirements' => [
                    'leadId'   => '\d+',
                    'objectId' => '\d+',
                ],
            ],
        ],
        'public' => [
            'mautic_ecommerce_track' => [
                'path'       => '/ecommerce/order',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::ecommerceAction',
                'method'     => 'POST',
            ],
            'mautic_ecommerce_track_legacy' => [
                'path'       => '/mtc/ecommerce',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::ecommerceAction',
                'method'     => 'POST',
            ],
        ],
    ],
    'services' => [
        'integrations' => [
            'mautic.integration.ecommerceconnector' => [
                'class'     => MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],
];
