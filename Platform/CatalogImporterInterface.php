<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Platform;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Pulls a platform's whole catalog through its API, for the first import and as a safety net
 * for missed webhooks.
 */
#[AutoconfigureTag(self::TAG)]
interface CatalogImporterInterface
{
    public const TAG = 'ecommerce.catalog_importer';

    public function getPlatform(): string;

    public function isConfigured(): bool;

    /**
     * @return iterable<array<string, mixed>> raw product objects for StorePlatformInterface::mapProduct()
     */
    public function fetchProducts(): iterable;
}
