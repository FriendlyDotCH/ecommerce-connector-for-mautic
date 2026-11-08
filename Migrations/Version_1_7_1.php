<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Product alerts (back in stock, price drop, low inventory): detected catalog changes waiting
 * for the alerts command, and which contact was alerted about which product.
 */
class Version_1_7_1 extends AbstractMigration
{
    private const CHANGES_TABLE = 'ecommerce_product_changes';

    private const ALERTS_TABLE = 'ecommerce_product_alerts';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::CHANGES_TABLE))
            || !$schema->hasTable($this->concatPrefix(self::ALERTS_TABLE));
    }

    protected function up(): void
    {
        $schema  = $this->entityManager->getConnection()->createSchemaManager()->introspectSchema();
        $changes = $this->concatPrefix(self::CHANGES_TABLE);
        $alerts  = $this->concatPrefix(self::ALERTS_TABLE);

        if (!$schema->hasTable($changes)) {
            $this->addSql(
                "CREATE TABLE `{$changes}` (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                store VARCHAR(64) NOT NULL,
                external_id VARCHAR(191) NOT NULL,
                change_type VARCHAR(32) NOT NULL,
                old_value DECIMAL(19,4) DEFAULT NULL,
                new_value DECIMAL(19,4) DEFAULT NULL,
                detected_at DATETIME NOT NULL,
                processed_at DATETIME DEFAULT NULL,
                INDEX ecommerce_product_change_pending (processed_at, detected_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }

        if (!$schema->hasTable($alerts)) {
            $this->addSql(
                "CREATE TABLE `{$alerts}` (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED NOT NULL,
                change_id INT UNSIGNED DEFAULT NULL,
                store VARCHAR(64) NOT NULL,
                external_id VARCHAR(191) NOT NULL,
                alert_type VARCHAR(32) NOT NULL,
                old_value DECIMAL(19,4) DEFAULT NULL,
                new_value DECIMAL(19,4) DEFAULT NULL,
                alerted_at DATETIME NOT NULL,
                INDEX ecommerce_product_alert_lead (lead_id, alerted_at),
                INDEX ecommerce_product_alert_repeat (lead_id, store, external_id, alert_type, alerted_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
            );
        }
    }
}
