<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_5_0 extends AbstractMigration
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

        return !$ordersTable->hasColumn('status') || !$itemsTable->hasColumn('refunded_qty');
    }

    protected function up(): void
    {
        $ordersTableName = $this->concatPrefix(self::ORDERS_TABLE);
        $itemsTableName  = $this->concatPrefix(self::ITEMS_TABLE);

        $schema      = $this->entityManager->getConnection()->createSchemaManager()->introspectSchema();
        $ordersTable = $schema->getTable($ordersTableName);

        if (!$ordersTable->hasColumn('status')) {
            // Orders recorded before 1.5.0 carry no status or refunds: treat them as paid in full.
            $this->addSql("ALTER TABLE `{$ordersTableName}`
                ADD `status` VARCHAR(32) NOT NULL DEFAULT 'paid' AFTER `order_total`,
                ADD `refunded_total` DECIMAL(19,4) NOT NULL DEFAULT 0 AFTER `status`,
                ADD `net_total` DECIMAL(19,4) NOT NULL DEFAULT 0 AFTER `refunded_total`");
            $this->addSql("UPDATE `{$ordersTableName}` SET `net_total` = `order_total`");
            $this->addSql("CREATE INDEX `ecommerce_status` ON `{$ordersTableName}` (`status`)");
        }

        if (!$schema->getTable($itemsTableName)->hasColumn('refunded_qty')) {
            $this->addSql("ALTER TABLE `{$itemsTableName}` ADD `refunded_qty` INT NOT NULL DEFAULT 0 AFTER `qty`");
        }
    }
}
