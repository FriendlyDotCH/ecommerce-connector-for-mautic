<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldUpdater;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class LeadLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EcommerceOrderRepository $orderRepository,
        private LeadCommerceFieldUpdater $leadCommerceFieldUpdater,
        private Config $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_DELETE => ['onLeadPreDelete', 0],
            LeadEvents::LEAD_POST_MERGE => ['onLeadMerge', 0],
        ];
    }

    public function onLeadPreDelete(LeadEvent $event): void
    {
        $leadId = (int) $event->getLead()->getId();
        if ($leadId <= 0) {
            return;
        }

        if (Config::ON_CONTACT_DELETE_ANONYMIZE === $this->config->getOnContactDelete()) {
            $this->orderRepository->anonymizeByLeadId($leadId);

            return;
        }

        $this->orderRepository->deleteByLeadId($leadId);
    }

    public function onLeadMerge(LeadMergeEvent $event): void
    {
        $loserId  = (int) $event->getLoser()->getId();
        $victor   = $event->getVictor();
        $victorId = (int) $victor->getId();

        $this->orderRepository->updateLead($loserId, $victorId);
        $this->leadCommerceFieldUpdater->syncForLead($victor);
    }
}
