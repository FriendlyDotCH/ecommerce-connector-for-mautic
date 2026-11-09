<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;

/**
 * The API representation of a catalog product. Field names match what store connectors send,
 * except that the shop's product ID is external_id (id is Mautic's).
 */
class ProductNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(EcommerceProduct $product): array
    {
        return [
            'id'             => $product->getId(),
            'store'          => $product->getStore(),
            'external_id'    => $product->getExternalId(),
            'sku'            => $product->getSku(),
            'name'           => $product->getName(),
            'url'            => $product->getUrl(),
            'image_url'      => $product->getImageUrl(),
            'price'          => $product->getPrice(),
            'regular_price'  => $product->getRegularPrice(),
            'sale_price'     => $product->getSalePrice(),
            'currency'       => $product->getCurrency(),
            'category'       => $product->getCategory(),
            'categories'     => $product->getCategories(),
            'variation_ids'  => $product->getVariationIds(),
            'stock_status'   => $product->getStockStatus(),
            'stock_quantity' => $product->getStockQuantity(),
            'status'         => $product->getStatus(),
            'created_at'     => self::formatDate($product->getDateCreated()),
            'updated_at'     => self::formatDate($product->getDateModified()),
            'date_added'     => self::formatDate($product->getDateAdded()),
            'date_updated'   => self::formatDate($product->getDateUpdated()),
        ];
    }

    private static function formatDate(?\DateTimeInterface $date): ?string
    {
        return null === $date ? null : \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
