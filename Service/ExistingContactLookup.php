<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\ClickthroughHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

/**
 * Resolves an existing Mautic contact by email, or by the tracking hash of an email Mautic sent.
 * Never creates contacts.
 */
final class ExistingContactLookup
{
    public function __construct(
        private LeadModel $leadModel,
        private Connection $connection,
    ) {
    }

    public function findByEmail(mixed $email): ?Lead
    {
        if (!is_scalar($email)) {
            return null;
        }

        $cleaned = InputHelper::email(strtolower(trim((string) $email)));
        if ('' === $cleaned || false === filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $lead = $this->leadModel->checkForDuplicateContact(['email' => $cleaned]);
        if (!$lead->getId()) {
            return null;
        }

        return $lead;
    }

    /**
     * The contact an email was sent to, from that send's tracking hash (the "stat" in Mautic's
     * "ct" link parameter). The hash is random per send, so only someone who received the email
     * has it. The contact ID in "ct" is never used: it is unsigned and can be changed.
     */
    public function findByEmailStat(mixed $hash): ?Lead
    {
        if (!is_string($hash) || 1 !== preg_match('/^[a-zA-Z0-9]{10,64}$/', $hash)) {
            return null;
        }

        $leadId = $this->connection->fetchOne(
            'SELECT s.lead_id FROM '.MAUTIC_TABLE_PREFIX.'email_stats s WHERE s.tracking_hash = ? AND s.lead_id IS NOT NULL LIMIT 1',
            [$hash]
        );

        if (false === $leadId || null === $leadId) {
            return null;
        }

        $lead = $this->leadModel->getEntity((int) $leadId);

        return $lead instanceof Lead && $lead->getId() ? $lead : null;
    }

    /**
     * The send's tracking hash from a "stat" value or a "ct" link parameter (encoded or decoded).
     *
     * @param array<string, mixed> $input
     */
    public static function emailStatFrom(array $input): ?string
    {
        if (isset($input['stat']) && is_string($input['stat'])) {
            return $input['stat'];
        }

        $ct = $input['ct'] ?? null;
        if (is_string($ct) && '' !== $ct) {
            try {
                $ct = ClickthroughHelper::decodeArrayFromUrl($ct);
            } catch (\Throwable) {
                return null;
            }
        }

        return is_array($ct) && isset($ct['stat']) && is_string($ct['stat']) ? $ct['stat'] : null;
    }
}
