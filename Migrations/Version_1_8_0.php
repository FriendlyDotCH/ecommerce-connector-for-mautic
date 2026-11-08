<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Coupons issued by the "Issue coupon" campaign action, and the order that used each.
 */
class Version_1_8_0 extends AbstractMigration
{
    private const TABLE = 'ecommerce_coupons';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql(
            "CREATE TABLE `{$table}` (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED NOT NULL,
                campaign_id INT UNSIGNED DEFAULT NULL,
                campaign_event_id INT UNSIGNED DEFAULT NULL,
                store VARCHAR(64) NOT NULL,
                code VARCHAR(64) NOT NULL,
                discount_type VARCHAR(16) NOT NULL,
                amount DECIMAL(19,4) NOT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                minimum_amount DECIMAL(19,4) DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                issued_at DATETIME NOT NULL,
                redeemed_order_id INT UNSIGNED DEFAULT NULL,
                redeemed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX ecommerce_coupon_store_code (store, code),
                INDEX ecommerce_coupon_lead (lead_id, issued_at),
                INDEX ecommerce_coupon_lead_event (lead_id, campaign_event_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );
    }
}
