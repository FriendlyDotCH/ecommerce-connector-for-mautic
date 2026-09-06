<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Service\Attribute\Required;

class EcommerceExportHelper extends ExportHelper
{
    private ContactOrderExportFormatter $orderExportFormatter;

    #[Required]
    public function autowireEcommerceExportHelper(
        ContactOrderExportFormatter $orderExportFormatter,
    ): void {
        $this->orderExportFormatter = $orderExportFormatter;
    }

    public function parseLeadToExport(Lead $lead): array
    {
        return $this->orderExportFormatter->appendToLeadRow(parent::parseLeadToExport($lead), $lead);
    }

    public function exportDataAs($data, string $type, string $filename): StreamedResponse
    {
        return parent::exportDataAs(
            $this->orderExportFormatter->appendToAliasValueExport($data, $filename),
            $type,
            $filename
        );
    }
}
