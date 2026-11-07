<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Event;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use Symfony\Contracts\EventDispatcher\Event;

final class EcommerceOrderEvent extends Event
{
    public function __construct(
        private EcommerceOrder $order,
    ) {
    }

    public function getOrder(): EcommerceOrder
    {
        return $this->order;
    }
}
