<?php

return [
    'name'        => 'Ecommerce Connector',
    'description' => 'Track eCommerce orders from your store and attribute revenue to Mautic emails. Configure the webhook secret and endpoint under Plugins > Ecommerce Connector.',
    'version'     => '1.6.0',
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
        'api' => [
            'mautic_api_ecommerce_orders' => [
                'path'       => '/ecommerce/orders',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::listAction',
                'method'     => 'GET',
            ],
            'mautic_api_ecommerce_order_new' => [
                'path'       => '/ecommerce/orders/new',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::newAction',
                'method'     => 'POST',
            ],
            'mautic_api_ecommerce_order' => [
                'path'         => '/ecommerce/orders/{id}',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::getAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_api_ecommerce_order_edit' => [
                'path'         => '/ecommerce/orders/{id}/edit',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::editAction',
                'method'       => ['PATCH', 'PUT'],
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_api_ecommerce_order_delete' => [
                'path'         => '/ecommerce/orders/{id}/delete',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::deleteAction',
                'method'       => 'DELETE',
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_api_ecommerce_event_new' => [
                'path'       => '/ecommerce/events/new',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\EventApiController::newAction',
                'method'     => 'POST',
            ],
            'mautic_api_ecommerce_contact_events' => [
                'path'         => '/contacts/{id}/ecommerce/events',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\EventApiController::contactEventsAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_api_ecommerce_contact_orders' => [
                'path'         => '/contacts/{id}/ecommerce/orders',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\OrderApiController::contactOrdersAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
        ],
        'public' => [
            'mautic_ecommerce_track' => [
                'path'       => '/ecommerce/order',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::ecommerceAction',
                'method'     => 'POST',
            ],
            'mautic_ecommerce_event' => [
                'path'       => '/ecommerce/event',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::eventAction',
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
