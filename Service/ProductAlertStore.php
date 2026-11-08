<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\DTO\ProductChange;
use Doctrine\DBAL\Connection;

/**
 * The product alert tables: catalog changes waiting for the alerts command, and the alerts sent
 * to contacts (for "once per product" and for the alert email tokens).
 */
class ProductAlertStore
{
    /** A contact gets the same alert for the same product at most once in this many days. */
    public const REPEAT_DAYS = 7;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<array{type: string, old: string|null, new: string|null}> $changes
     */
    public function queueChanges(string $store, string $externalId, array $changes): void
    {
        $now = self::now();
        foreach ($changes as $change) {
            $this->connection->insert(MAUTIC_TABLE_PREFIX.'ecommerce_product_changes', [
                'store'       => $store,
                'external_id' => $externalId,
                'change_type' => $change['type'],
                'old_value'   => $change['old'],
                'new_value'   => $change['new'],
                'detected_at' => $now,
            ]);
        }
    }

    /**
     * Claims up to $limit waiting changes, oldest first; overlapping runs cannot claim the same one.
     *
     * With $collectMinutes, nothing is claimed until the oldest waiting change is that old: a sale
     * set up product by product then goes out together, as one alert email per contact.
     *
     * @return list<array{id: int, store: string, external_id: string, change_type: string, old_value: string|null, new_value: string|null, detected_at: string}>
     */
    public function claimPendingChanges(int $limit, int $collectMinutes = 0): array
    {
        if ($collectMinutes > 0) {
            $oldest = $this->connection->fetchOne('SELECT MIN(detected_at) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_changes WHERE processed_at IS NULL');
            if (!is_string($oldest) || $oldest > self::now(sprintf('-%d minutes', $collectMinutes))) {
                return [];
            }
        }

        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_changes WHERE processed_at IS NULL ORDER BY detected_at, id LIMIT '.max(1, $limit)
        );

        $claimed = [];
        foreach ($ids as $id) {
            $updated = $this->connection->executeStatement(
                'UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_product_changes SET processed_at = ? WHERE id = ? AND processed_at IS NULL',
                [self::now(), (int) $id]
            );
            if (1 === $updated) {
                $row = $this->connection->fetchAssociative(
                    'SELECT id, store, external_id, change_type, old_value, new_value, detected_at FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_changes WHERE id = ?',
                    [(int) $id]
                );
                if (false !== $row) {
                    $row['id'] = (int) $row['id'];
                    $claimed[] = $row;
                }
            }
        }

        return $claimed;
    }

    public function wasAlertedRecently(int $leadId, string $store, string $externalId, string $alertType): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ? AND store = ? AND external_id = ? AND alert_type = ? AND alerted_at > ? LIMIT 1',
            [$leadId, $store, $externalId, $alertType, (new \DateTimeImmutable(sprintf('-%d days', self::REPEAT_DAYS), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Whether the contact got any product alert since the given moment (the per-contact cap).
     */
    public function wasAlertedSince(int $leadId, \DateTimeInterface $since): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ? AND alerted_at > ? LIMIT 1',
            [$leadId, \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * @param string|null $alertedAt shared by the alerts of one email (see latestAlertBatch)
     */
    public function recordAlert(int $leadId, ProductChange $change, string $alertType, ?string $alertedAt = null): void
    {
        $this->connection->insert(MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts', [
            'lead_id'     => $leadId,
            'change_id'   => $change->id,
            'store'       => $change->store,
            'external_id' => $change->externalId,
            'alert_type'  => $alertType,
            'old_value'   => $change->oldValue,
            'new_value'   => $change->newValue,
            'alerted_at'  => $alertedAt ?? self::now(),
        ]);
    }

    /**
     * The contact's latest alert, with its product, for the alert email tokens.
     *
     * @return array<string, mixed>|null
     */
    public function latestAlertWithProduct(int $leadId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.alert_type, a.old_value, a.new_value, a.alerted_at, p.name, p.url, p.image_url, p.price, p.regular_price, p.currency, p.stock_quantity
             FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts a
             JOIN '.MAUTIC_TABLE_PREFIX.'ecommerce_products p ON p.store = a.store AND p.external_id = a.external_id
             WHERE a.lead_id = ? ORDER BY a.alerted_at DESC, a.id DESC LIMIT 1',
            [$leadId]
        );

        return false !== $row ? $row : null;
    }

    /**
     * The alerts of the contact's latest alert email: same type, recorded in the same run.
     * One row per product (its latest change), with the product id, oldest change first.
     *
     * @return list<array<string, mixed>>
     */
    public function latestAlertBatch(int $leadId): array
    {
        $latest = $this->connection->fetchAssociative(
            'SELECT alert_type, alerted_at FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ? ORDER BY alerted_at DESC, id DESC LIMIT 1',
            [$leadId]
        );
        if (false === $latest) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id AS alert_id, a.alert_type, a.old_value, a.new_value, p.id AS product_id
             FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts a
             JOIN '.MAUTIC_TABLE_PREFIX.'ecommerce_products p ON p.store = a.store AND p.external_id = a.external_id
             WHERE a.lead_id = ? AND a.alert_type = ? AND a.alerted_at = ? ORDER BY a.id',
            [$leadId, $latest['alert_type'], $latest['alerted_at']]
        );

        // Two drops of one product in a batch: the first old price, the last new price.
        $byProduct = [];
        foreach ($rows as $row) {
            $id = (int) $row['product_id'];
            $byProduct[$id] = isset($byProduct[$id]) ? ['new_value' => $row['new_value']] + $byProduct[$id] : $row;
        }

        return array_values($byProduct);
    }

    public function deleteAlert(int $leadId, int $changeId): void
    {
        $this->connection->executeStatement('DELETE FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ? AND change_id = ?', [$leadId, $changeId]);
    }

    public function deleteAlertsForLead(int $leadId): void
    {
        $this->connection->executeStatement('DELETE FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts WHERE lead_id = ?', [$leadId]);
    }

    public function moveAlerts(int $fromLeadId, int $toLeadId): void
    {
        $this->connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_product_alerts SET lead_id = ? WHERE lead_id = ?', [$toLeadId, $fromLeadId]);
    }

    /**
     * Processed changes and alerts older than the cutoff.
     */
    public function deleteOlderThan(\DateTimeInterface $before, bool $dryRun): int
    {
        $cutoff = $before->format('Y-m-d H:i:s');
        $tables = [
            'ecommerce_product_changes' => 'processed_at IS NOT NULL AND detected_at < ?',
            'ecommerce_product_alerts'  => 'alerted_at < ?',
        ];

        $count = 0;
        foreach ($tables as $table => $where) {
            $count += $dryRun
                ? (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.$table.' WHERE '.$where, [$cutoff])
                : $this->connection->executeStatement('DELETE FROM '.MAUTIC_TABLE_PREFIX.$table.' WHERE '.$where, [$cutoff]);
        }

        return $count;
    }

    public static function now(string $modifier = 'now'): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
