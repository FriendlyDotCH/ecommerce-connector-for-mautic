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
];
