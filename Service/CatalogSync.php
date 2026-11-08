<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;

/**
 * Keeps the catalog in sync and gives order lines without a category the category of their
 * product, also for orders recorded before the product arrived.
 */
class CatalogSync
{
    public function __construct(
        private EcommerceProductRepository $productRepository,
        private CommerceRecalculator $commerceRecalculator,
        private ProductAlertStore $alertStore,
    ) {
    }

    /**
     * @param array<string, mixed> $product mapped product
     * @param bool                 $recalculate false during bulk imports, which recalculate once at the end
     *
     * @return list<int> contacts whose order lines gained a category
     */
    public function upsert(string $store, array $product, bool $recalculate = true): array
    {
        // Back in stock, price drop, stock going down: queued for the product alerts command.
        $changes = ProductChangeDetector::detect($this->productRepository->findStoredState($store, (string) $product['external_id']), $product);

        $this->productRepository->upsertProduct($store, $product);

        if ([] !== $changes) {
            $this->alertStore->queueChanges($store, (string) $product['external_id'], $changes);
        }
        $leadIds = $this->productRepository->fillMissingCategories($store, (string) $product['external_id']);

        // Behaviours and purchase history by category change for these contacts.
        if ($recalculate) {
            foreach ($leadIds as $leadId) {
                $this->commerceRecalculator->recalculateForLead($leadId);
            }
        }

        return $leadIds;
    }

    /**
     * @param list<int> $leadIds
     */
    public function recalculate(array $leadIds): void
    {
        foreach (array_unique($leadIds) as $leadId) {
            $this->commerceRecalculator->recalculateForLead($leadId);
        }
    }

    /**
     * @return bool whether the product existed
     */
    public function delete(string $store, string $externalId): bool
    {
        return $this->productRepository->deleteByExternalId($store, $externalId);
    }

    public function categoryForLine(string $store, ?string $productId, string $itemId): ?string
    {
        return $this->productRepository->findCategoryForLine($store, $productId, $itemId);
    }
}
