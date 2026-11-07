<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * A product in the store catalog, kept in sync from the store. Written with bulk SQL by
 * EcommerceProductRepository::upsertProduct(); mapped for reading.
 */
class EcommerceProduct
{
    private ?int $id = null;

    private string $store;

    private string $externalId;

    private ?string $sku = null;

    private string $name;

    private ?string $url = null;

    private ?string $imageUrl = null;

    private ?string $price = null;

    private ?string $regularPrice = null;

    private ?string $salePrice = null;

    private ?string $currency = null;

    /**
     * Main category, used for order lines.
     */
    private ?string $category = null;

    /**
     * @var list<string>|null
     */
    private ?array $categories = null;

    /**
     * @var list<string>|null
     */
    private ?array $variationIds = null;

    private ?string $stockStatus = null;

    private ?int $stockQuantity = null;

    private ?string $status = null;

    private ?\DateTimeInterface $dateModified = null;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateUpdated;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ecommerce_products')
            ->setCustomRepositoryClass(EcommerceProductRepository::class)
            ->addUniqueConstraint(['store', 'external_id'], 'ecommerce_product_store_external')
            ->addIndex(['sku'], 'ecommerce_product_sku')
            ->addIndex(['category'], 'ecommerce_product_category');

        $builder->addId();
        $builder->addField('store', 'string', ['length' => 64]);
        $builder->addNamedField('externalId', 'string', 'external_id');
        $builder->addNullableField('sku', 'string', 'sku');
        $builder->addField('name', 'string');
        $builder->addNullableField('url', 'text', 'url');
        $builder->addNullableField('imageUrl', 'text', 'image_url');
        foreach (['price' => 'price', 'regularPrice' => 'regular_price', 'salePrice' => 'sale_price'] as $field => $column) {
            $builder->addField($field, 'decimal', ['columnName' => $column, 'precision' => 19, 'scale' => 4, 'nullable' => true]);
        }
        $builder->addField('currency', 'string', ['length' => 3, 'nullable' => true]);
        $builder->addNullableField('category', 'string', 'category');
        $builder->addNullableField('categories', 'json', 'categories');
        $builder->addNullableField('variationIds', 'json', 'variation_ids');
        $builder->addField('stockStatus', 'string', ['columnName' => 'stock_status', 'length' => 32, 'nullable' => true]);
        $builder->addNullableField('stockQuantity', 'integer', 'stock_quantity');
        $builder->addField('status', 'string', ['length' => 32, 'nullable' => true]);
        $builder->addNamedField('dateModified', 'datetime', 'date_modified', true);
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateUpdated', 'datetime', 'date_updated');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStore(): string
    {
        return $this->store;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function getRegularPrice(): ?string
    {
        return $this->regularPrice;
    }

    public function getSalePrice(): ?string
    {
        return $this->salePrice;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return $this->categories ?? [];
    }

    /**
     * @return list<string>
     */
    public function getVariationIds(): array
    {
        return $this->variationIds ?? [];
    }

    public function getStockStatus(): ?string
    {
        return $this->stockStatus;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getDateModified(): ?\DateTimeInterface
    {
        return $this->dateModified;
    }
}
