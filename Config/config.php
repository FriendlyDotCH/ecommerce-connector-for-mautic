<?php

return [
    'name'        => 'Ecommerce Connector',
    'description' => 'Track eCommerce orders from your store and attribute revenue to Mautic emails. Configure the webhook secret and endpoint under Plugins > Ecommerce Connector.',
    'version'     => '1.4.0',
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
            'mautic_ecommerce_behaviour_index' => [
                'path'       => '/ecommerce/behaviours',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\BehaviourController::indexAction',
            ],
            'mautic_ecommerce_behaviour_action' => [
                'path'       => '/ecommerce/behaviours/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\BehaviourController::executeAction',
                'defaults'   => ['objectId' => 0],
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
    'menu' => [
        'admin' => [
            'mautic.plugin.ecommerce.behaviour.menu' => [
                'id'        => 'mautic_ecommerce_behaviour_index',
                'route'     => 'mautic_ecommerce_behaviour_index',
                'iconClass' => 'ri-shopping-bag-3-line',
                'access'    => 'lead:fields:full',
                'priority'  => 45,
            ],
        ],
    ],
];
