<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

/**
 * Resolves an existing Mautic contact by email only. Never creates contacts.
 */
final class ExistingContactLookup
{
    public function __construct(
        private LeadModel $leadModel,
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
}
