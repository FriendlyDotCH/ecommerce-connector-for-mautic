<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Migrations;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\IntegrationsBundle\Migration\Engine;
use Mautic\PluginBundle\Entity\Plugin;
use PHPUnit\Framework\Assert;

class Version_1_3_0Test extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPreMigrationSchema();
    }

    public function testMigrationAddsMerchantLocationAndItemsTable(): void
    {
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['bundle' => 'EcommerceConnectorBundle']);
        if (null === $plugin) {
            $plugin = new Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
        }
        $plugin->setVersion('1.2.0');
        $this->em->persist($plugin);
        $this->em->flush();

        $engine = new Engine(
            $this->em,
            MAUTIC_TABLE_PREFIX,
            dirname(__DIR__, 3),
            'EcommerceConnectorBundle'
        );
        $engine->up();

        $schema      = $this->connection->createSchemaManager()->introspectSchema();
        $ordersTable = $schema->getTable(MAUTIC_TABLE_PREFIX.'ecommerce_orders');

        Assert::assertTrue($ordersTable->hasColumn('merchant'));
        Assert::assertTrue($ordersTable->hasColumn('location'));
        Assert::assertTrue($schema->hasTable(MAUTIC_TABLE_PREFIX.'ecommerce_order_items'));
    }

    private function createPreMigrationSchema(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items');
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.'ecommerce_orders');

        $this->connection->executeStatement(
            'CREATE TABLE '.MAUTIC_TABLE_PREFIX.'ecommerce_orders (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                email_id INT UNSIGNED DEFAULT NULL,
                order_id VARCHAR(191) NOT NULL,
                order_total DECIMAL(19,4) NOT NULL,
                order_currency VARCHAR(191) DEFAULT NULL,
                order_source VARCHAR(191) NOT NULL,
                date_added DATETIME NOT NULL,
                UNIQUE INDEX ecommerce_order_source_unique (order_id, order_source),
                INDEX ecommerce_email_id (email_id),
                INDEX ecommerce_lead_id (lead_id),
                INDEX ecommerce_order_id (order_id),
                INDEX ecommerce_date_added (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }
}
