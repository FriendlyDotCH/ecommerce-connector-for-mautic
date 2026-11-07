<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;

trait InstallsEcommerceSchema
{
    private function installEcommerceSchema(Connection $connection): void
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_order_items");
        $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_orders");
        $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_behaviours");

        $connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_orders (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                email_id INT UNSIGNED DEFAULT NULL,
                campaign_id INT UNSIGNED DEFAULT NULL,
                order_id VARCHAR(191) NOT NULL,
                order_total DECIMAL(19,4) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'paid',
                refunded_total DECIMAL(19,4) NOT NULL DEFAULT 0,
                net_total DECIMAL(19,4) NOT NULL DEFAULT 0,
                exchange_rate DECIMAL(19,8) DEFAULT NULL,
                base_net_total DECIMAL(19,4) DEFAULT NULL,
                order_currency VARCHAR(191) DEFAULT NULL,
                order_source VARCHAR(191) NOT NULL,
                location VARCHAR(191) DEFAULT NULL,
                order_date DATETIME NOT NULL,
                date_added DATETIME NOT NULL,
                UNIQUE INDEX ecommerce_order_source_unique (order_id, order_source),
                INDEX ecommerce_email_id (email_id),
                INDEX ecommerce_lead_id (lead_id),
                INDEX ecommerce_order_id (order_id),
                INDEX ecommerce_date_added (date_added),
                INDEX ecommerce_lead_order_date (lead_id, order_date),
                INDEX ecommerce_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );

        $connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_order_items (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                item_id VARCHAR(191) NOT NULL,
                item_name VARCHAR(191) NOT NULL,
                qty INT NOT NULL,
                refunded_qty INT NOT NULL DEFAULT 0,
                category VARCHAR(191) DEFAULT NULL,
                unit_price DECIMAL(19,4) DEFAULT NULL,
                INDEX ecommerce_item_lead_id (lead_id),
                INDEX ecommerce_item_item_id (item_id),
                INDEX ecommerce_item_item_name (item_name),
                INDEX ecommerce_item_category (category),
                INDEX IDX_ECOM_ITEM_ORDER (order_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );

        $connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_behaviours (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                name VARCHAR(191) NOT NULL,
                field_alias VARCHAR(191) NOT NULL,
                metric VARCHAR(16) NOT NULL,
                scope VARCHAR(16) NOT NULL,
                filter_values LONGTEXT DEFAULT NULL,
                days INT NOT NULL,
                is_published TINYINT(1) NOT NULL,
                date_added DATETIME DEFAULT NULL,
                last_calculated DATETIME DEFAULT NULL,
                UNIQUE INDEX ecommerce_behaviour_field_alias (field_alias),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );

        $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_exchange_rates");
        $connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_exchange_rates (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                rate_date DATE NOT NULL,
                currency VARCHAR(3) NOT NULL,
                per_eur DECIMAL(19,8) NOT NULL,
                UNIQUE INDEX ecommerce_rate_date_currency (currency, rate_date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
