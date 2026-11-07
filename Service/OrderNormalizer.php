<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;

/**
 * The API representation of an order.
 */
class OrderNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(EcommerceOrder $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'item_id'    => $item->getItemId(),
                'item_name'  => $item->getItemName(),
                'qty'        => $item->getQty(),
                'category'   => $item->getCategory(),
                'unit_price' => $item->getUnitPrice(),
            ];
        }

        return [
            'id'             => $order->getId(),
            'order_id'       => $order->getOrderId(),
            'order_total'    => $order->getOrderTotal(),
            'order_currency' => $order->getOrderCurrency(),
            'order_source'   => $order->getOrderSource(),
            'location'       => $order->getLocation(),
            'order_date'     => self::formatDate($order->getOrderDate()),
            'date_added'     => self::formatDate($order->getDateAdded()),
            'contact_id'     => $order->getLead()?->getId(),
            'email_id'       => $order->getEmail()?->getId(),
            'items'          => $items,
        ];
    }

    private static function formatDate(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
