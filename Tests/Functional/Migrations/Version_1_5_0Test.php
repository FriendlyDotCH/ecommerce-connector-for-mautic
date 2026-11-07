<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Migrations;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\IntegrationsBundle\Migration\Engine;
use Mautic\PluginBundle\Entity\Plugin;
use PHPUnit\Framework\Assert;

class Version_1_5_0Test extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPreMigrationSchema();
    }

    public function testMigrationAddsStatusAndRefunds(): void
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $this->connection->executeStatement(
            "INSERT INTO {$prefix}ecommerce_orders (order_id, order_total, order_source, date_added)
             VALUES ('legacy-1', 10, 'web', '2026-01-02 03:04:05')"
        );

        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['bundle' => 'EcommerceConnectorBundle']);
        if (null === $plugin) {
            $plugin = new Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
        }
        $plugin->setVersion('1.4.0');
        $this->em->persist($plugin);
        $this->em->flush();

        (new Engine($this->em, MAUTIC_TABLE_PREFIX, dirname(__DIR__, 3), 'EcommerceConnectorBundle'))->up();

        $schema      = $this->connection->createSchemaManager()->introspectSchema();
        $ordersTable = $schema->getTable($prefix.'ecommerce_orders');

        foreach (['status', 'refunded_total', 'net_total'] as $column) {
            Assert::assertTrue($ordersTable->hasColumn($column), $column);
        }
        Assert::assertTrue($schema->getTable($prefix.'ecommerce_order_items')->hasColumn('refunded_qty'));

        // Existing orders count as paid in full.
        Assert::assertSame(
            ['status' => 'paid', 'net_total' => '10.0000'],
            $this->connection->fetchAssociative("SELECT status, net_total FROM {$prefix}ecommerce_orders WHERE order_id = 'legacy-1'")
        );
    }

    private function createPreMigrationSchema(): void
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        $this->connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_order_items");
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_orders");
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$prefix}ecommerce_behaviours");

        $this->connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_orders (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                email_id INT UNSIGNED DEFAULT NULL,
                order_id VARCHAR(191) NOT NULL,
                order_total DECIMAL(19,4) NOT NULL,
                order_currency VARCHAR(191) DEFAULT NULL,
                order_source VARCHAR(191) NOT NULL,
                location VARCHAR(191) DEFAULT NULL,
                date_added DATETIME NOT NULL,
                UNIQUE INDEX ecommerce_order_source_unique (order_id, order_source),
                INDEX ecommerce_lead_id (lead_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );

        $this->connection->executeStatement(
            "CREATE TABLE {$prefix}ecommerce_order_items (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                item_id VARCHAR(191) NOT NULL,
                item_name VARCHAR(191) NOT NULL,
                qty INT NOT NULL,
                category VARCHAR(191) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB"
        );
    }
}
