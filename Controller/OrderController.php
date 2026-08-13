<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\LocationFormatter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends FormController
{
    use LeadAccessTrait;

    public function viewAction(
        Request $request,
        EcommerceOrderRepository $orderRepository,
        EcommerceOrderItemRepository $orderItemRepository,
        Config $config,
        CurrencyFormatter $currencyFormatter,
        LocationFormatter $locationFormatter,
        int $leadId,
        int $objectId,
    ): Response {
        $lead = $this->checkLeadAccess($leadId, 'view');
        if ($lead instanceof Response) {
            return $lead;
        }

        $order = $orderRepository->getEntity($objectId);
        if (!$order instanceof EcommerceOrder) {
            return $this->notFound();
        }

        $orderLead = $order->getLead();
        if (null === $orderLead || (int) $orderLead->getId() !== (int) $lead->getId()) {
            return $this->accessDenied();
        }

        $currency = strtoupper((string) ($order->getOrderCurrency() ?? $config->getDefaultCurrency()));
        $amount   = number_format((float) $order->getOrderTotal(), 2, '.', '');
        $items    = [];

        foreach ($orderItemRepository->getItemsGroupedByOrderIds([$objectId])[$objectId] ?? [] as $item) {
            $items[] = [
                'itemId'   => $item['item_id'],
                'itemName' => $item['item_name'],
                'qty'      => $item['qty'],
                'category' => $item['category'],
            ];
        }

        return $this->delegateView([
            'viewParameters' => [
                'order' => [
                    'id'                  => $objectId,
                    'orderId'             => $order->getOrderId(),
                    'orderSource'         => $order->getOrderSource(),
                    'location'            => $locationFormatter->format($order->getLocation()),
                    'orderTotalFormatted' => $currencyFormatter->format($currency, $amount),
                    'dateAdded'           => $order->getDateAdded(),
                    'items'               => $items,
                ],
            ],
            'contentTemplate' => '@EcommerceConnector/Lead/order_details.html.twig',
            'passthroughVars' => [
                'route' => false,
            ],
        ]);
    }
}
