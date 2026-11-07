<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Finds the campaign behind an order's attributed email: the email stat records whether the
 * send came from a campaign event.
 */
class CampaignAttributor
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed>|null $clickthrough
     */
    public function resolveCampaignId(?Email $email, ?Lead $lead, ?array $clickthrough = null): ?int
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $stat   = false;

        // The click token names the exact send.
        if (isset($clickthrough['stat']) && is_string($clickthrough['stat']) && '' !== $clickthrough['stat']) {
            $stat = $this->connection->fetchAssociative(
                "SELECT source, source_id FROM {$prefix}email_stats WHERE tracking_hash = ? LIMIT 1",
                [$clickthrough['stat']]
            );
        }

        if (false === $stat && null !== $email && null !== $lead?->getId()) {
            $stat = $this->connection->fetchAssociative(
                "SELECT source, source_id FROM {$prefix}email_stats WHERE email_id = ? AND lead_id = ? ORDER BY date_sent DESC, id DESC LIMIT 1",
                [$email->getId(), $lead->getId()]
            );
        }

        if (false === $stat || 'campaign.event' !== $stat['source'] || empty($stat['source_id'])) {
            return null;
        }

        $campaignId = $this->connection->fetchOne(
            "SELECT campaign_id FROM {$prefix}campaign_events WHERE id = ?",
            [(int) $stat['source_id']]
        );

        return false !== $campaignId && null !== $campaignId ? (int) $campaignId : null;
    }
}
