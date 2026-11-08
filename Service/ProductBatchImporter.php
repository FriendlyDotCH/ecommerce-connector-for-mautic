<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

/**
 * Applies a catalog update in the standard format: one product, {"products": [...]}, and/or
 * {"store": "...", "deleted": ["id", ...]}. Used by the signed /ecommerce/product endpoint and
 * the catalog REST API. Products in a batch inherit the request's store.
 */
class ProductBatchImporter
{
    public const MAX_PRODUCTS = 250;

    public function __construct(
        private ProductPayloadParser $parser,
        private CatalogSync $catalogSync,
    ) {
    }

    /**
     * Number of products and deletions in the request, to refuse oversized batches up front.
     *
     * @param array<string, mixed> $input
     */
    public static function count(array $input): int
    {
        return count(self::products($input)) + count(is_array($input['deleted'] ?? null) ? $input['deleted'] : []);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     saved: list<array{store: string, external_id: string}>,
     *     deleted: list<array{store: string, external_id: string, existed: bool}>,
     *     errors: list<array{index: int|string, message: string}>
     * } index is the position in products, or "deleted.N" for the deleted list
     */
    public function import(array $input, string $context): array
    {
        $result  = ['saved' => [], 'deleted' => [], 'errors' => []];
        $leadIds = [];

        foreach (array_slice(self::products($input), 0, self::MAX_PRODUCTS) as $index => $productInput) {
            if (!is_array($productInput)) {
                $result['errors'][] = ['index' => $index, 'message' => 'A product must be an object.'];
                continue;
            }

            $productInput += ['store' => $input['store'] ?? null];

            if (!empty($productInput['deleted'])) {
                $deleted = $this->delete($productInput['store'], $productInput['id'] ?? $productInput['product_id'] ?? null);
                if (is_string($deleted)) {
                    $result['errors'][] = ['index' => $index, 'message' => $deleted];
                } else {
                    $result['deleted'][] = $deleted;
                }
                continue;
            }

            $parsed = $this->parser->parse($productInput, $context);
            if (null === $parsed) {
                $result['errors'][] = ['index' => $index, 'message' => (string) $this->parser->getLastError()];
                continue;
            }

            $leadIds           = [...$leadIds, ...$this->catalogSync->upsert($parsed['store'], $parsed['product'], false)];
            $result['saved'][] = ['store' => $parsed['store'], 'external_id' => (string) $parsed['product']['external_id']];
        }

        foreach (is_array($input['deleted'] ?? null) ? array_values($input['deleted']) : [] as $index => $id) {
            $deleted = $this->delete($input['store'] ?? null, $id);
            if (is_string($deleted)) {
                $result['errors'][] = ['index' => 'deleted.'.$index, 'message' => $deleted];
            } else {
                $result['deleted'][] = $deleted;
            }
        }

        // Once per request, not per product.
        $this->catalogSync->recalculate($leadIds);

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<int, mixed>
     */
    private static function products(array $input): array
    {
        if (isset($input['products']) && is_array($input['products'])) {
            return array_values($input['products']);
        }

        return isset($input['id']) || isset($input['product_id']) ? [$input] : [];
    }

    /**
     * @return array{store: string, external_id: string, existed: bool}|string the deletion, or why it was refused
     */
    private function delete(mixed $storeInput, mixed $id): array|string
    {
        $store = $this->parser->store($storeInput);
        if (null === $store) {
            return 'store must match the order_source the store uses for orders, and be allowed.';
        }
        if (!is_scalar($id) || '' === trim((string) $id)) {
            return 'id is required to delete a product.';
        }

        $externalId = trim((string) $id);

        return ['store' => $store, 'external_id' => $externalId, 'existed' => $this->catalogSync->delete($store, $externalId)];
    }
}
