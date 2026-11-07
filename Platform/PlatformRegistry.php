<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Platform;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PlatformRegistry
{
    /**
     * @var array<string, StorePlatformInterface>
     */
    private array $platforms = [];

    /**
     * @var array<string, CatalogImporterInterface>
     */
    private array $importers = [];

    /**
     * @param iterable<StorePlatformInterface>   $platforms
     * @param iterable<CatalogImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator(StorePlatformInterface::TAG)]
        iterable $platforms,
        #[AutowireIterator(CatalogImporterInterface::TAG)]
        iterable $importers,
    ) {
        foreach ($platforms as $platform) {
            $this->platforms[$platform->getName()] = $platform;
        }

        foreach ($importers as $importer) {
            $this->importers[$importer->getPlatform()] = $importer;
        }
    }

    public function getPlatform(string $name): ?StorePlatformInterface
    {
        return $this->platforms[strtolower($name)] ?? null;
    }

    public function getImporter(string $platform): ?CatalogImporterInterface
    {
        return $this->importers[strtolower($platform)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getImporterNames(): array
    {
        return array_keys($this->importers);
    }
}
