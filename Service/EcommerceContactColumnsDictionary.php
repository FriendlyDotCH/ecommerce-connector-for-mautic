<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds Total revenue after Points on the contacts list when Ecommerce Connector is published.
 *
 * Mautic 7.2 made Mautic\LeadBundle\Services\ContactColumnsDictionary final, so this no longer
 * replaces the core service. Instead it post-processes the core columns array and is applied
 * to the contact list templates by ContactListColumnsSubscriber.
 */
final class EcommerceContactColumnsDictionary
{
    public function __construct(
        private TranslatorInterface $translator,
        private Config $ecommerceConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $columns Column alias => label, as returned by the core ContactColumnsDictionary
     *
     * @return array<string, mixed>
     */
    public function addRevenueColumn(array $columns): array
    {
        if (!$this->ecommerceConfig->isPublished()) {
            return $columns;
        }

        $alias = LeadCommerceFieldDefinition::LIFETIME_VALUE;
        if (isset($columns[$alias])) {
            return $columns;
        }

        $label    = $this->translator->trans('mautic.plugin.ecommerce.list.total_revenue');
        $result   = [];
        $inserted = false;

        foreach ($columns as $key => $value) {
            $result[$key] = $value;
            if ('points' === $key) {
                $result[$alias] = $label;
                $inserted       = true;
            }
        }

        if (!$inserted) {
            $withRevenue = [];
            foreach ($result as $key => $value) {
                if ('id' === $key && !isset($withRevenue[$alias])) {
                    $withRevenue[$alias] = $label;
                }
                $withRevenue[$key] = $value;
            }
            if (!isset($withRevenue[$alias])) {
                $withRevenue[$alias] = $label;
            }

            return $withRevenue;
        }

        return $result;
    }
}
