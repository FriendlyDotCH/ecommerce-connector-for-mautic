<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_4_0 extends AbstractMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const ITEMS_TABLE = 'ecommerce_order_items';

    private const BEHAVIOURS_TABLE = 'ecommerce_behaviours';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $ordersTable = $schema->getTable($this->concatPrefix(self::ORDERS_TABLE));
            $itemsTable  = $schema->getTable($this->concatPrefix(self::ITEMS_TABLE));
        } catch (SchemaException) {
            return false;
        }

        return !$ordersTable->hasColumn('order_date')
            || !$itemsTable->hasColumn('unit_price')
            || !$schema->hasTable($this->concatPrefix(self::BEHAVIOURS_TABLE));
    }

    protected function up(): void
    {
        $ordersTableName = $this->concatPrefix(self::ORDERS_TABLE);
        $itemsTableName  = $this->concatPrefix(self::ITEMS_TABLE);

        $schema      = $this->entityManager->getConnection()->createSchemaManager()->introspectSchema();
        $ordersTable = $schema->getTable($ordersTableName);
        $itemsTable  = $schema->getTable($itemsTableName);

        if (!$ordersTable->hasColumn('order_date')) {
            // Orders recorded before 1.4.0 have no store-side date; the recording time is the best we know.
            $this->addSql("ALTER TABLE `{$ordersTableName}` ADD `order_date` DATETIME DEFAULT NULL AFTER `location`");
            $this->addSql("UPDATE `{$ordersTableName}` SET `order_date` = `date_added`");
            $this->addSql("ALTER TABLE `{$ordersTableName}` MODIFY `order_date` DATETIME NOT NULL");
            $this->addSql("CREATE INDEX `ecommerce_lead_order_date` ON `{$ordersTableName}` (`lead_id`, `order_date`)");
        }

        if (!$itemsTable->hasColumn('unit_price')) {
            $this->addSql("ALTER TABLE `{$itemsTableName}` ADD `unit_price` DECIMAL(19,4) DEFAULT NULL AFTER `category`");
        }

        $behavioursTableName = $this->concatPrefix(self::BEHAVIOURS_TABLE);
        if (!$schema->hasTable($behavioursTableName)) {
            $this->addSql(
                "CREATE TABLE `{$behavioursTableName}` (
                    `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    `name` VARCHAR(191) NOT NULL,
                    `field_alias` VARCHAR(191) NOT NULL,
                    `metric` VARCHAR(16) NOT NULL,
                    `scope` VARCHAR(16) NOT NULL,
                    `filter_values` LONGTEXT DEFAULT NULL,
                    `days` INT NOT NULL,
                    `is_published` TINYINT(1) NOT NULL,
                    `date_added` DATETIME DEFAULT NULL,
                    `last_calculated` DATETIME DEFAULT NULL,
                    UNIQUE INDEX `ecommerce_behaviour_field_alias` (`field_alias`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }
    }
}
