<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Psr\Log\LoggerInterface;

/**
 * Runs a campaign decision for one contact from a webhook, the API or cron, where no visitor is
 * tracked: the contact is set as system contact around the run and reset afterwards.
 *
 * A failing campaign run never fails the caller: the order, event or product change is already
 * stored, and a 500 would make shops retry into the duplicate check. The failure is logged with
 * enough context to find the contact and the trigger.
 */
class CampaignDecisionRunner
{
    public function __construct(
        private RealTimeExecutioner $realTimeExecutioner,
        private ContactTracker $contactTracker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool false when the campaign run failed
     */
    public function run(string $decision, Lead $lead, object $passthrough, ?int $channelId): bool
    {
        if (!$lead->getId()) {
            return true;
        }

        $this->contactTracker->setSystemContact($lead);

        try {
            $this->realTimeExecutioner->execute($decision, $passthrough, 'ecommerce', $channelId);
        } catch (\Throwable $exception) {
            $this->logger->error('Ecommerce campaign decision could not be run: '.$exception->getMessage(), [
                'decision'   => $decision,
                'contact_id' => $lead->getId(),
                'channel_id' => $channelId,
                'exception'  => $exception,
            ]);

            return false;
        } finally {
            $this->contactTracker->setSystemContact(null);
        }

        return true;
    }
}
