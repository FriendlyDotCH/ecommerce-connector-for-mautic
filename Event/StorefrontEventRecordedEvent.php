<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Event;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use Symfony\Contracts\EventDispatcher\Event;

final class StorefrontEventRecordedEvent extends Event
{
    public function __construct(
        private EcommerceEvent $storefrontEvent,
    ) {
    }

    public function getStorefrontEvent(): EcommerceEvent
    {
        return $this->storefrontEvent;
    }
}
