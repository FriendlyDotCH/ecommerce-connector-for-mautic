<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Services\ContactColumnsDictionary;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds Total revenue after Points on the contacts list when Ecommerce Connector is published.
 */
final class EcommerceContactColumnsDictionary extends ContactColumnsDictionary
{
    public function __construct(
        FieldModel $fieldModel,
        private TranslatorInterface $translator,
        CoreParametersHelper $coreParametersHelper,
        private Config $ecommerceConfig,
    ) {
        parent::__construct($fieldModel, $translator, $coreParametersHelper);
    }

    public function getColumns(): array
    {
        $columns = parent::getColumns();
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
