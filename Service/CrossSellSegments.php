<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;

/**
 * With "Create cross-sell segments" on, one segment per cross-sell category ("Cross-sell:
 * Diffusers", filter ecommerce_cross_sell_category = Diffusers), created when the first contacts
 * get that opportunity. Segments are never changed or removed afterwards: campaigns may use them.
 * Mautic's segment update cron fills them.
 */
class CrossSellSegments
{
    public const ALIAS_PREFIX = 'ecommerce-cross-sell-';

    /** At most this many new segments per run, so a messy category list cannot flood Segments. */
    public const MAX_NEW_PER_RUN = 20;

    public function __construct(
        private Connection $connection,
        private ListModel $listModel,
        private Config $config,
    ) {
    }

    /**
     * @return int segments created
     */
    public function sync(): int
    {
        if (!$this->config->isCrossSellSegmentsEnabled()) {
            return 0;
        }

        $column     = $this->connection->quoteIdentifier(F::CROSS_SELL_CATEGORY);
        $categories = $this->connection->fetchFirstColumn(
            'SELECT '.$column.' FROM '.MAUTIC_TABLE_PREFIX."leads WHERE {$column} IS NOT NULL AND {$column} <> '' GROUP BY {$column} ORDER BY COUNT(*) DESC"
        );

        $existing = array_flip(array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT alias FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias LIKE ?',
            [self::ALIAS_PREFIX.'%']
        )));

        $created = 0;
        foreach ($categories as $category) {
            $alias = self::alias((string) $category);
            if (isset($existing[$alias]) || $created >= self::MAX_NEW_PER_RUN) {
                continue;
            }

            $segment = new LeadList();
            $segment->setName(mb_substr('Cross-sell: '.$category, 0, 191));
            $segment->setPublicName(mb_substr('Cross-sell: '.$category, 0, 191));
            $segment->setAlias($alias);
            $segment->setDescription('Created by the Ecommerce Connector: contacts whose cross-sell category is '.$category.'.');
            $segment->setIsPublished(true);
            $segment->setFilters([[
                'glue'       => 'and',
                'field'      => F::CROSS_SELL_CATEGORY,
                'object'     => 'lead',
                'type'       => 'text',
                'operator'   => '=',
                'properties' => ['filter' => (string) $category],
            ]]);

            $this->listModel->saveEntity($segment);
            $existing[$alias] = true;
            ++$created;
        }

        return $created;
    }

    /**
     * A stable alias per category; a hash keeps categories apart that slug alike.
     */
    public static function alias(string $category): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $category) ?: $category)), '-');

        return self::ALIAS_PREFIX.mb_substr('' !== $slug ? $slug : 'category', 0, 40).'-'.substr(md5($category), 0, 6);
    }
}
