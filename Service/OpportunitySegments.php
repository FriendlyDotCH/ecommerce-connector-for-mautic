<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;

/**
 * Segments made from the Opportunities page: one per opportunity, with the same filters the page
 * counts with. Created on request, found again by alias, never changed afterwards (campaigns may
 * use them). Mautic's segment update cron fills them.
 */
class OpportunitySegments
{
    public const ALIAS_PREFIX = 'ecommerce-opportunity-';

    public function __construct(
        private Connection $connection,
        private ListModel $listModel,
    ) {
    }

    /**
     * Existing segments per opportunity key. Cross-sell opportunities also match the segments
     * that "Create cross-sell segments" made.
     *
     * @param list<array{key: string, type: string, name: string}> $opportunities
     *
     * @return array<string, array{id: int, alias: string, name: string}>
     */
    public function findExisting(array $opportunities): array
    {
        $aliases = [];
        foreach ($opportunities as $opportunity) {
            foreach (self::aliasesFor($opportunity) as $alias) {
                $aliases[$alias] = $opportunity['key'];
            }
        }

        if ([] === $aliases) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, alias, name FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias IN (?) ORDER BY id',
            [array_keys($aliases)],
            [Connection::PARAM_STR_ARRAY]
        );

        $found = [];
        foreach ($rows as $row) {
            $key = $aliases[$row['alias']] ?? null;
            if (null !== $key && !isset($found[$key])) {
                $found[$key] = ['id' => (int) $row['id'], 'alias' => (string) $row['alias'], 'name' => (string) $row['name']];
            }
        }

        return $found;
    }

    /**
     * @param array{key: string, type: string, name: string, filters: list<array<string, mixed>>} $opportunity
     *
     * @return array{segment: LeadList, created: bool}
     */
    public function create(array $opportunity, string $name, string $description): array
    {
        $existing = $this->findExisting([$opportunity])[$opportunity['key']] ?? null;
        if (null !== $existing) {
            $segment = $this->listModel->getEntity($existing['id']);
            if ($segment instanceof LeadList) {
                return ['segment' => $segment, 'created' => false];
            }
        }

        $segment = new LeadList();
        $segment->setName(mb_substr($name, 0, 191));
        $segment->setPublicName(mb_substr($name, 0, 191));
        $segment->setAlias(self::alias($opportunity['key']));
        $segment->setDescription($description);
        $segment->setIsPublished(true);
        $segment->setFilters($opportunity['filters']);

        $this->listModel->saveEntity($segment);

        return ['segment' => $segment, 'created' => true];
    }

    /**
     * A stable alias per opportunity key; the hash keeps keys apart that slug alike.
     */
    public static function alias(string $key): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $key) ?: $key)), '-');

        return self::ALIAS_PREFIX.mb_substr('' !== $slug ? $slug : 'opportunity', 0, 40).'-'.substr(md5($key), 0, 6);
    }

    /**
     * @param array{key: string, type: string, name: string} $opportunity
     *
     * @return list<string>
     */
    private static function aliasesFor(array $opportunity): array
    {
        $aliases = [self::alias($opportunity['key'])];
        if (OpportunityFinder::TYPE_CROSS_SELL === $opportunity['type'] && '' !== $opportunity['name']) {
            $aliases[] = CrossSellSegments::alias($opportunity['name']);
        }

        return $aliases;
    }
}
