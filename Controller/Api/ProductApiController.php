<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller\Api;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\CatalogSync;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductBatchImporter;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductNormalizer;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * REST API for the product catalog under /api. Reading needs the same permission as the catalog
 * page (view own contacts); writing changes contacts' order-line categories and behaviours and
 * can fire product alerts, so it needs "edit others", like the orders API.
 */
#[AsController]
class ProductApiController
{
    public const MAX_LIMIT = 200;

    public const READ_PERMISSION = 'lead:leads:viewown';

    public const WRITE_PERMISSION = 'lead:leads:editother';

    private const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    public function __construct(
        private CorePermissions $security,
        private EcommerceProductRepository $productRepository,
        private ProductBatchImporter $importer,
        private CatalogSync $catalogSync,
        private ProductNormalizer $normalizer,
    ) {
    }

    public function listAction(Request $request): Response
    {
        if (!$this->security->isGranted(self::READ_PERMISSION)) {
            return $this->error('You do not have access to the catalog.', Response::HTTP_FORBIDDEN);
        }

        $filters = [];
        foreach (['store', 'category', 'sku', 'external_id', 'search'] as $name) {
            $value = mb_substr(trim((string) $request->query->get($name, '')), 0, 191);
            if ('' !== $value) {
                $filters[$name] = 'store' === $name ? strtolower($value) : $value;
            }
        }

        $stock = (string) $request->query->get('stock_status', '');
        if ('' !== $stock) {
            if (!in_array($stock, self::STOCK_STATUSES, true)) {
                return $this->error('stock_status must be instock, outofstock or onbackorder.', Response::HTTP_BAD_REQUEST);
            }
            $filters['stock_status'] = $stock;
        }

        if ('' !== (string) $request->query->get('since', '')) {
            try {
                $filters['since'] = new \DateTimeImmutable((string) $request->query->get('since'), new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return $this->error('since must be a date.', Response::HTTP_BAD_REQUEST);
            }
        }

        $limit  = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', 30)));
        $result = $this->productRepository->findForApi($filters, max(0, $request->query->getInt('start')), $limit);

        return new JsonResponse([
            'total'    => $result['total'],
            'products' => array_map($this->normalizer->normalize(...), $result['products']),
        ]);
    }

    public function getAction(int $id): Response
    {
        if (!$this->security->isGranted(self::READ_PERMISSION)) {
            return $this->error('You do not have access to the catalog.', Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if (!$product instanceof EcommerceProduct) {
            return $this->error('Product not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['product' => $this->normalizer->normalize($product)]);
    }

    /**
     * Creates or updates products by store and the shop's product ID, and deletes products; the
     * same body as the store connectors' /ecommerce/product. Answers 200 with what was saved,
     * deleted and refused (by position), or 400 when nothing could be applied.
     */
    public function batchAction(Request $request): Response
    {
        if (!$this->security->isGranted(self::WRITE_PERMISSION)) {
            return $this->error('You do not have access to edit the catalog.', Response::HTTP_FORBIDDEN);
        }

        $input = WebhookRequestBody::getInput($request);
        $count = ProductBatchImporter::count($input);
        if (0 === $count) {
            return $this->error('Send a product, {"products": [...]} and/or {"store": "...", "deleted": [...]}.', Response::HTTP_BAD_REQUEST);
        }
        if ($count > ProductBatchImporter::MAX_PRODUCTS) {
            return $this->error(sprintf('At most %d products and deletions per request.', ProductBatchImporter::MAX_PRODUCTS), Response::HTTP_BAD_REQUEST);
        }

        $result = $this->importer->import($input, 'api');

        $products = [];
        foreach ($result['saved'] as $saved) {
            $product = $this->productRepository->findStoredState($saved['store'], $saved['external_id']);
            if (null !== $product) {
                $products[] = $this->normalizer->normalize($product);
            }
        }

        $body = ['products' => $products, 'deleted' => $result['deleted'], 'errors' => $result['errors']];

        if ([] === $result['saved'] && [] === $result['deleted']) {
            return $this->error('No product could be saved or deleted.', Response::HTTP_BAD_REQUEST, ['errors' => $result['errors']]);
        }

        return new JsonResponse($body);
    }

    public function deleteAction(int $id): Response
    {
        if (!$this->security->isGranted(self::WRITE_PERMISSION)) {
            return $this->error('You do not have access to edit the catalog.', Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if (!$product instanceof EcommerceProduct) {
            return $this->error('Product not found.', Response::HTTP_NOT_FOUND);
        }

        $data = $this->normalizer->normalize($product);
        $this->productRepository->detachEntity($product);
        $this->catalogSync->delete($product->getStore(), $product->getExternalId());

        return new JsonResponse(['product' => $data]);
    }

    /**
     * Same error shape as Mautic's core API.
     *
     * @param array<string, mixed> $details
     */
    private function error(string $message, int $code, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'code'    => $code,
                'message' => $message,
                'details' => $details,
                'type'    => null,
            ]],
        ], $code);
    }
}
