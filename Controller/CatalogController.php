<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductSales;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The synced product catalog, read-only: products are edited in the shop.
 */
class CatalogController extends CommonController
{
    /** Anyone who can see contacts can see what they buy. */
    public const PERMISSION = 'lead:leads:viewown';

    public const LIMIT = 50;

    private const STOCK_FILTERS = ['instock', 'outofstock', 'onbackorder'];

    public function indexAction(Request $request, EcommerceProductRepository $productRepository, ProductSales $sales, int $page = 1): Response
    {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 191);
        $store  = (string) $request->query->get('store', '');
        $stock  = in_array($request->query->get('stock'), self::STOCK_FILTERS, true) ? (string) $request->query->get('stock') : '';
        $stores = $productRepository->findStores();
        $store  = in_array($store, $stores, true) ? $store : '';

        $result     = $productRepository->findCatalogPage($search, $store, $stock, max(1, $page), self::LIMIT);
        $totalPages = max(1, (int) ceil($result['total'] / self::LIMIT));
        if ($page > $totalPages) {
            $result = $productRepository->findCatalogPage($search, $store, $stock, $page = $totalPages, self::LIMIT);
        }

        $ids = array_map(static fn (EcommerceProduct $product): int => (int) $product->getId(), $result['items']);

        $filters = array_filter(['search' => $search, 'store' => $store, 'stock' => $stock], static fn (string $value): bool => '' !== $value);

        return $this->delegateView([
            'viewParameters'  => [
                'items'       => $result['items'],
                'total'       => $result['total'],
                'page'        => $page,
                'totalPages'  => $totalPages,
                'sold'        => $sales->unitsSold($ids),
                'salesDays'   => ProductSales::DEFAULT_DAYS,
                'search'      => $search,
                'store'       => $store,
                'stock'       => $stock,
                'stores'      => $stores,
                'stockFilters' => self::STOCK_FILTERS,
                'filters'     => $filters,
            ],
            'contentTemplate' => '@EcommerceConnector/Catalog/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_ecommerce_catalog_index',
                'route'         => $this->generateUrl('mautic_ecommerce_catalog_index', ['page' => $page] + $filters),
                'mauticContent' => 'ecommercecatalog',
            ],
        ]);
    }
}
