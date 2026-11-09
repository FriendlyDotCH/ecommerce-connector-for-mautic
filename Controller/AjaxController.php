<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use MauticPlugin\EcommerceConnectorBundle\Controller\CatalogController;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductBlocks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AjaxController extends CommonAjaxController
{
    public function dismissPrivacyNoticeAction(PrivacyUpgradeNotice $privacyUpgradeNotice): JsonResponse
    {
        if (true !== $this->security->isGranted('plugin:plugins:manage')) {
            return $this->sendJsonResponse(['success' => 0], Response::HTTP_FORBIDDEN);
        }

        $privacyUpgradeNotice->dismiss();

        return $this->sendJsonResponse(['success' => 1]);
    }

    /**
     * Catalog categories for the email builder's product block.
     */
    public function productCategoriesAction(EcommerceProductRepository $productRepository): JsonResponse
    {
        if (true !== $this->security->isGranted(CatalogController::PERMISSION)) {
            return $this->sendJsonResponse(['categories' => []], Response::HTTP_FORBIDDEN);
        }

        return $this->sendJsonResponse(['categories' => $productRepository->findCategoryNames()]);
    }

    /**
     * Example product cards for the email builder's product block, as the email shows them
     * without a contact: sources that depend on the contact show best sellers instead.
     */
    public function productBlockPreviewAction(Request $request, ProductBlocks $productBlocks): JsonResponse
    {
        if (true !== $this->security->isGranted(CatalogController::PERMISSION)) {
            return $this->sendJsonResponse(['html' => ''], Response::HTTP_FORBIDDEN);
        }

        $source   = (string) $request->query->get('source', ProductBlocks::SOURCE_RECOMMENDED);
        $personal = !in_array($source, [ProductBlocks::SOURCE_RECOMMENDED, ProductBlocks::SOURCE_BEST_SELLERS, ProductBlocks::SOURCE_NEW_ARRIVALS], true);
        $products = $productBlocks->products([
            'source'   => $personal ? ProductBlocks::SOURCE_BEST_SELLERS : $source,
            'limit'    => (string) $request->query->getInt('limit', ProductBlocks::DEFAULT_LIMIT),
            'category' => $personal ? '' : mb_substr((string) $request->query->get('category', ''), 0, 191),
        ], null);

        return $this->sendJsonResponse(['html' => $productBlocks->renderHtml($products), 'example' => $personal]);
    }
}
