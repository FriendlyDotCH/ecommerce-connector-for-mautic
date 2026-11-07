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

    private const RATES_TABLE = 'ecommerce_exchange_rates';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $ordersTable = $schema->getTable($this->concatPrefix(self::ORDERS_TABLE));
            $itemsTable  = $schema->getTable($this->concatPrefix(self::ITEMS_TABLE));
        } catch (SchemaException) {
            return false;
        }

        return !$ordersTable->hasColumn('status')
            || !$ordersTable->hasColumn('campaign_id')
            || !$ordersTable->hasColumn('base_net_total')
            || !$schema->hasTable($this->concatPrefix(self::RATES_TABLE))
            || !$itemsTable->hasColumn('refunded_qty');
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

        if (!$ordersTable->hasColumn('campaign_id')) {
            $this->addSql("ALTER TABLE `{$ordersTableName}` ADD `campaign_id` INT UNSIGNED DEFAULT NULL AFTER `email_id`");
            $this->addSql("CREATE INDEX `ecommerce_campaign_id` ON `{$ordersTableName}` (`campaign_id`)");
            // Attribute past orders through the email stats, as new orders are.
            $prefix = $this->concatPrefix('');
            $this->addSql("UPDATE `{$ordersTableName}` eo
                JOIN `{$prefix}email_stats` s ON s.id = (
                    SELECT s2.id FROM `{$prefix}email_stats` s2
                    WHERE s2.email_id = eo.email_id AND s2.lead_id = eo.lead_id
                    ORDER BY s2.date_sent DESC, s2.id DESC LIMIT 1
                )
                JOIN `{$prefix}campaign_events` ce ON ce.id = s.source_id
                SET eo.campaign_id = ce.campaign_id
                WHERE eo.email_id IS NOT NULL AND eo.lead_id IS NOT NULL AND s.source = 'campaign.event'");
        }

        if (!$ordersTable->hasColumn('base_net_total')) {
            // Filled by mautic:ecommerce:exchange-rates --rebase once a base currency is set.
            $this->addSql("ALTER TABLE `{$ordersTableName}`
                ADD `exchange_rate` DECIMAL(19,8) DEFAULT NULL AFTER `net_total`,
                ADD `base_net_total` DECIMAL(19,4) DEFAULT NULL AFTER `exchange_rate`");
        }

        $ratesTableName = $this->concatPrefix(self::RATES_TABLE);
        if (!$schema->hasTable($ratesTableName)) {
            $this->addSql(
                "CREATE TABLE `{$ratesTableName}` (
                    `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    `rate_date` DATE NOT NULL,
                    `currency` VARCHAR(3) NOT NULL,
                    `per_eur` DECIMAL(19,8) NOT NULL,
                    UNIQUE INDEX `ecommerce_rate_date_currency` (`currency`, `rate_date`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }

        if (!$schema->getTable($itemsTableName)->hasColumn('refunded_qty')) {
            $this->addSql("ALTER TABLE `{$itemsTableName}` ADD `refunded_qty` INT NOT NULL DEFAULT 0 AFTER `qty`");
        }
    }
}
