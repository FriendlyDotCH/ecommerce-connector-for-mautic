<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_3_0 extends AbstractMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const ITEMS_TABLE = 'ecommerce_order_items';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $ordersTable = $schema->getTable($this->concatPrefix(self::ORDERS_TABLE));
        } catch (SchemaException) {
            return false;
        }

        if ($ordersTable->hasColumn('merchant')) {
            return true;
        }

        if (!$ordersTable->hasColumn('location')) {
            return true;
        }

        return !$schema->hasTable($this->concatPrefix(self::ITEMS_TABLE));
    }

    protected function up(): void
    {
        $ordersTableName = $this->concatPrefix(self::ORDERS_TABLE);
        $itemsTableName  = $this->concatPrefix(self::ITEMS_TABLE);

        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
        $schema        = $schemaManager->introspectSchema();
        $ordersTable   = $schema->getTable($ordersTableName);

        if ($ordersTable->hasColumn('merchant')) {
            $this->addSql("ALTER TABLE `{$ordersTableName}` DROP COLUMN `merchant`");
        }

        if (!$ordersTable->hasColumn('location')) {
            $this->addSql("ALTER TABLE `{$ordersTableName}` ADD `location` VARCHAR(191) DEFAULT NULL");
        }

        if (!$schema->hasTable($itemsTableName)) {
            // Indexes only (no FKs): order/lead id column types vary across installs (signed vs unsigned, int vs bigint).
            $this->addSql(
                "CREATE TABLE `{$itemsTableName}` (
                    `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    `order_id` INT UNSIGNED NOT NULL,
                    `lead_id` BIGINT UNSIGNED DEFAULT NULL,
                    `item_id` VARCHAR(191) NOT NULL,
                    `item_name` VARCHAR(191) NOT NULL,
                    `qty` INT NOT NULL,
                    `category` VARCHAR(191) DEFAULT NULL,
                    INDEX `ecommerce_item_lead_id` (`lead_id`),
                    INDEX `ecommerce_item_item_id` (`item_id`),
                    INDEX `ecommerce_item_item_name` (`item_name`),
                    INDEX `ecommerce_item_category` (`category`),
                    INDEX `IDX_ECOM_ITEM_ORDER` (`order_id`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }
    }
}
