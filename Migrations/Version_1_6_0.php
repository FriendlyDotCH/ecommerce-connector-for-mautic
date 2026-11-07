<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_6_0 extends AbstractMigration
{
    private const EVENTS_TABLE = 'ecommerce_events';

    protected function isApplicable(Schema $schema): bool
    {
        return $schema->hasTable($this->concatPrefix('ecommerce_orders'))
            && !$schema->hasTable($this->concatPrefix(self::EVENTS_TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::EVENTS_TABLE);

        // Index only, no foreign key: lead id column types vary across installs. Contact
        // deletes remove events through LeadLifecycleSubscriber.
        $this->addSql(
            "CREATE TABLE `{$table}` (
                `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
                `lead_id` BIGINT UNSIGNED NOT NULL,
                `event_type` VARCHAR(32) NOT NULL,
                `product_id` VARCHAR(191) DEFAULT NULL,
                `product_name` VARCHAR(191) DEFAULT NULL,
                `category` VARCHAR(191) DEFAULT NULL,
                `price` DECIMAL(19,4) DEFAULT NULL,
                `currency` VARCHAR(3) DEFAULT NULL,
                `product_url` LONGTEXT DEFAULT NULL,
                `image_url` LONGTEXT DEFAULT NULL,
                `cart_id` VARCHAR(191) DEFAULT NULL,
                `cart_value` DECIMAL(19,4) DEFAULT NULL,
                `cart_url` LONGTEXT DEFAULT NULL,
                `cart_items` JSON DEFAULT NULL,
                `source` VARCHAR(64) NOT NULL,
                `occurred_at` DATETIME NOT NULL,
                `abandoned_at` DATETIME DEFAULT NULL,
                `date_added` DATETIME NOT NULL,
                INDEX `ecommerce_event_lead_type_date` (`lead_id`, `event_type`, `occurred_at`),
                INDEX `ecommerce_event_type_date` (`event_type`, `occurred_at`),
                INDEX `ecommerce_event_occurred_at` (`occurred_at`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );
    }
}
