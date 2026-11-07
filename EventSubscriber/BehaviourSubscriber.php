<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourCalculator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class BehaviourSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BehaviourCalculator $behaviourCalculator,
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
            $this->behaviourCalculator->recalculateForLead((int) $leadId);
        }
    }

    public function onLeadMerge(LeadMergeEvent $event): void
    {
        $this->behaviourCalculator->recalculateForLead((int) $event->getVictor()->getId());
    }
}
