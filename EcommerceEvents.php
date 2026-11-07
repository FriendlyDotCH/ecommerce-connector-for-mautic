<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle;

final class EcommerceEvents
{
    /**
     * Dispatched with an EcommerceOrderEvent after a new order is saved and the contact's
     * commerce fields are updated. Also the timeline event type for orders.
     */
    public const ORDER_RECORDED = 'ecommerce.order.recorded';

    /**
     * Dispatched with a StorefrontEventRecordedEvent after a storefront event is saved.
     */
    public const STOREFRONT_EVENT_RECORDED = 'ecommerce.storefront_event.recorded';

    public const ON_CAMPAIGN_TRIGGER_DECISION = 'ecommerce.on_campaign_trigger_decision';

    public const ON_CAMPAIGN_TRIGGER_CONDITION = 'ecommerce.on_campaign_trigger_condition';
}
