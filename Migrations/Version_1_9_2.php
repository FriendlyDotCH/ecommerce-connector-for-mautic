<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * The store's created date of catalog products, for the new_arrivals product block. Filled in
 * as products are next synced; until then new arrivals go by when Mautic first saw a product.
 */
class Version_1_9_2 extends AbstractMigration
{
    private const TABLE = 'ecommerce_products';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            return !$schema->getTable($this->concatPrefix(self::TABLE))->hasColumn('date_created');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql("ALTER TABLE `{$table}` ADD `date_created` DATETIME DEFAULT NULL AFTER `date_modified`");
    }
}
