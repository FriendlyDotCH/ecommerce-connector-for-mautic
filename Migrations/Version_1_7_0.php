<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_7_0 extends AbstractMigration
{
    private const PRODUCTS_TABLE = 'ecommerce_products';

    private const ITEMS_TABLE = 'ecommerce_order_items';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $itemsTable = $schema->getTable($this->concatPrefix(self::ITEMS_TABLE));
        } catch (SchemaException) {
            return false;
        }

        return !$itemsTable->hasColumn('product_id') || !$schema->hasTable($this->concatPrefix(self::PRODUCTS_TABLE));
    }

    protected function up(): void
    {
        $schema         = $this->entityManager->getConnection()->createSchemaManager()->introspectSchema();
        $itemsTableName = $this->concatPrefix(self::ITEMS_TABLE);
        $productsTable  = $this->concatPrefix(self::PRODUCTS_TABLE);

        if (!$schema->getTable($itemsTableName)->hasColumn('product_id')) {
            // The store's product ID, so lines can be matched to the catalog.
            $this->addSql("ALTER TABLE `{$itemsTableName}` ADD `product_id` VARCHAR(191) DEFAULT NULL AFTER `item_id`");
            $this->addSql("CREATE INDEX `ecommerce_item_product_id` ON `{$itemsTableName}` (`product_id`)");
        }

        if (!$schema->hasTable($productsTable)) {
            $this->addSql(
                "CREATE TABLE `{$productsTable}` (
                    `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    `store` VARCHAR(64) NOT NULL,
                    `external_id` VARCHAR(191) NOT NULL,
                    `sku` VARCHAR(191) DEFAULT NULL,
                    `name` VARCHAR(191) NOT NULL,
                    `url` LONGTEXT DEFAULT NULL,
                    `image_url` LONGTEXT DEFAULT NULL,
                    `price` DECIMAL(19,4) DEFAULT NULL,
                    `regular_price` DECIMAL(19,4) DEFAULT NULL,
                    `sale_price` DECIMAL(19,4) DEFAULT NULL,
                    `currency` VARCHAR(3) DEFAULT NULL,
                    `category` VARCHAR(191) DEFAULT NULL,
                    `categories` JSON DEFAULT NULL,
                    `variation_ids` JSON DEFAULT NULL,
                    `stock_status` VARCHAR(32) DEFAULT NULL,
                    `stock_quantity` INT DEFAULT NULL,
                    `status` VARCHAR(32) DEFAULT NULL,
                    `date_modified` DATETIME DEFAULT NULL,
                    `date_added` DATETIME NOT NULL,
                    `date_updated` DATETIME NOT NULL,
                    UNIQUE INDEX `ecommerce_product_store_external` (`store`, `external_id`),
                    INDEX `ecommerce_product_sku` (`sku`),
                    INDEX `ecommerce_product_category` (`category`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }
    }
}
