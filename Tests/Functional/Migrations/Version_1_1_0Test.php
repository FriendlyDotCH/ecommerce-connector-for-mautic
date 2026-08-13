<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Migrations;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\IntegrationsBundle\Migration\Engine;
use Mautic\PluginBundle\Entity\Plugin;
use PHPUnit\Framework\Assert;

class Version_1_1_0Test extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacySchema();
    }

    public function testMigrationUpgradesLegacySchema(): void
    {
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['bundle' => 'EcommerceConnectorBundle']);
        if (null === $plugin) {
            $plugin = new Plugin();
            $plugin->setName('Ecommerce Connector');
            $plugin->setBundle('EcommerceConnectorBundle');
        }
        $plugin->setVersion('1.0.0');
        $this->em->persist($plugin);
        $this->em->flush();

        $engine = new Engine(
            $this->em,
            MAUTIC_TABLE_PREFIX,
            dirname(__DIR__, 3),
            'EcommerceConnectorBundle'
        );
        $engine->up();

        $schema = $this->connection->createSchemaManager()->introspectSchema();
        $table  = $schema->getTable(MAUTIC_TABLE_PREFIX.'ecommerce_orders');

        Assert::assertSame('decimal', $table->getColumn('order_total')->getType()->getName());
        Assert::assertTrue($table->hasIndex('ecommerce_date_added'));
    }

    private function createLegacySchema(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items');
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.'ecommerce_orders');

        $this->connection->executeStatement(
            'CREATE TABLE '.MAUTIC_TABLE_PREFIX.'ecommerce_orders (
                id INT AUTO_INCREMENT NOT NULL,
                lead_id INT DEFAULT NULL,
                email_id INT DEFAULT NULL,
                order_id VARCHAR(191) NOT NULL,
                order_total DOUBLE NOT NULL,
                order_currency VARCHAR(191) DEFAULT NULL,
                order_source VARCHAR(191) NOT NULL,
                date_added DATETIME NOT NULL,
                UNIQUE INDEX ecommerce_order_source_unique (order_id, order_source),
                INDEX ecommerce_email_id (email_id),
                INDEX ecommerce_lead_id (lead_id),
                INDEX ecommerce_order_id (order_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }
}
