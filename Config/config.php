<?php

return [
    'name'        => 'Ecommerce Connector',
    'description' => 'Track eCommerce orders from your store and attribute revenue to Mautic emails. Configure the webhook secret and endpoint under Plugins > Ecommerce Connector.',
    'version'     => '1.12.0',
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
            'mautic_ecommerce_catalog_index' => [
                'path'         => '/ecommerce/catalog/{page}',
                'controller'   => 'MauticPlugin\\EcommerceConnectorBundle\\Controller\\CatalogController::indexAction',
                'method'       => 'GET',
                'defaults'     => ['page' => 1],
                'requirements' => ['page' => '\\d+'],
            ],
            'mautic_ecommerce_opportunity_index' => [
                'path'       => '/ecommerce/opportunities',
                'controller' => 'MauticPlugin\\EcommerceConnectorBundle\\Controller\\OpportunityController::indexAction',
                'method'     => 'GET',
            ],
            'mautic_ecommerce_opportunity_segment' => [
                'path'       => '/ecommerce/opportunities/segment',
                'controller' => 'MauticPlugin\\EcommerceConnectorBundle\\Controller\\OpportunityController::segmentAction',
                'method'     => 'POST',
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
            'mautic_api_ecommerce_products' => [
                'path'       => '/ecommerce/products',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\ProductApiController::listAction',
                'method'     => 'GET',
            ],
            'mautic_api_ecommerce_products_batch' => [
                'path'       => '/ecommerce/products/batch',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\ProductApiController::batchAction',
                'method'     => 'POST',
            ],
            'mautic_api_ecommerce_product' => [
                'path'         => '/ecommerce/products/{id}',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\ProductApiController::getAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_api_ecommerce_product_delete' => [
                'path'         => '/ecommerce/products/{id}/delete',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\Api\ProductApiController::deleteAction',
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
            'mautic_ecommerce_platform_webhook' => [
                'path'         => '/ecommerce/webhook/{platform}',
                'controller'   => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::platformWebhookAction',
                'method'       => 'POST',
                'requirements' => ['platform' => '[a-z0-9_-]+'],
            ],
            'mautic_ecommerce_woocommerce' => [
                'path'       => '/ecommerce/woocommerce',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::platformWebhookAction',
                'method'     => 'POST',
                'defaults'   => ['platform' => 'woocommerce'],
            ],
            'mautic_ecommerce_product' => [
                'path'       => '/ecommerce/product',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::productAction',
                'method'     => 'POST',
            ],
            'mautic_ecommerce_event' => [
                'path'       => '/ecommerce/event',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::eventAction',
                'method'     => 'POST',
            ],
            'mautic_ecommerce_ping' => [
                'path'       => '/ecommerce/ping',
                'controller' => 'MauticPlugin\EcommerceConnectorBundle\Controller\PublicController::pingAction',
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
        'main' => [
            'mautic.plugin.ecommerce.menu.root' => [
                'id'        => 'mautic_ecommerce_root',
                'iconClass' => 'ri-shopping-cart-2-fill',
                'access'    => ['lead:leads:viewown', 'lead:fields:full'],
                'priority'  => 55,
                'children'  => [
                    'mautic.plugin.ecommerce.catalog.menu' => [
                        'id'       => 'mautic_ecommerce_catalog_index',
                        'route'    => 'mautic_ecommerce_catalog_index',
                        'access'   => 'lead:leads:viewown',
                        'priority' => 30,
                    ],
                    'mautic.plugin.ecommerce.opportunity.menu' => [
                        'id'       => 'mautic_ecommerce_opportunity_index',
                        'route'    => 'mautic_ecommerce_opportunity_index',
                        'access'   => 'lead:leads:viewown',
                        'priority' => 20,
                    ],
                    'mautic.plugin.ecommerce.behaviour.menu' => [
                        'id'       => 'mautic_ecommerce_behaviour_index',
                        'route'    => 'mautic_ecommerce_behaviour_index',
                        'access'   => 'lead:fields:full',
                        'priority' => 10,
                    ],
                ],
            ],
        ],
    ],
];
