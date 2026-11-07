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

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $ordersTable = $schema->getTable($this->concatPrefix(self::ORDERS_TABLE));
            $itemsTable  = $schema->getTable($this->concatPrefix(self::ITEMS_TABLE));
        } catch (SchemaException) {
            return false;
        }

        return !$ordersTable->hasColumn('order_date') || !$itemsTable->hasColumn('unit_price');
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
    }
}
