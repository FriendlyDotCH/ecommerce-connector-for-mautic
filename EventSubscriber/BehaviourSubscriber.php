<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\Service\CommerceRecalculator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps computed contact values (timing metrics and behaviours) current after order changes.
 */
final class BehaviourSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CommerceRecalculator $commerceRecalculator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before CampaignSubscriber, so campaign steps see the updated values.
            EcommerceEvents::ORDER_RECORDED => ['onOrderRecorded', 10],
            LeadEvents::LEAD_POST_MERGE     => ['onLeadMerge', -10],
        ];
    }

    public function onOrderRecorded(EcommerceOrderEvent $event): void
    {
        $leadId = $event->getOrder()->getLead()?->getId();
        if (null !== $leadId) {
            $this->commerceRecalculator->recalculateForLead((int) $leadId);
        }
    }

    public function onLeadMerge(LeadMergeEvent $event): void
    {
        $this->commerceRecalculator->recalculateForLead((int) $event->getVictor()->getId());
    }
}
