<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Coupon;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The ecommerce_coupons table: issued codes per contact, and which order used each.
 */
class CouponStore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array{lead_id: int, campaign_id: int|null, campaign_event_id: int|null, store: string, code: string, discount_type: string, amount: string, currency: string|null, minimum_amount: string|null, expires_at: string|null} $coupon
     */
    public function insert(array $coupon): void
    {
        $this->connection->insert(MAUTIC_TABLE_PREFIX.'ecommerce_coupons', $coupon + ['issued_at' => self::now()]);
    }

    /**
     * An unused, unexpired coupon the action already issued to this contact (a retried or
     * repeated campaign run reuses it instead of creating another).
     *
     * @return array<string, mixed>|null
     */
    public function findOpenForEvent(int $leadId, int $campaignEventId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons
             WHERE lead_id = ? AND campaign_event_id = ? AND redeemed_at IS NULL AND (expires_at IS NULL OR expires_at > ?)
             ORDER BY issued_at DESC, id DESC LIMIT 1',
            [$leadId, $campaignEventId, self::now()]
        );

        return false !== $row ? $row : null;
    }

    /**
     * The contact's newest coupon that can still be used, for the email tokens.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestOpen(int $leadId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons
             WHERE lead_id = ? AND redeemed_at IS NULL AND (expires_at IS NULL OR expires_at > ?)
             ORDER BY issued_at DESC, id DESC LIMIT 1',
            [$leadId, self::now()]
        );

        return false !== $row ? $row : null;
    }

    public function hasRedeemedInCampaign(int $leadId, int $campaignId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons WHERE lead_id = ? AND campaign_id = ? AND redeemed_at IS NOT NULL LIMIT 1',
            [$leadId, $campaignId]
        );
    }

    /**
     * Marks the store's coupons with these codes as used by the order. Codes compare
     * case-insensitively (the table's collation), as shops treat them.
     *
     * @param list<string> $codes
     *
     * @return int coupons marked
     */
    public function redeem(string $store, array $codes, int $orderId): int
    {
        if ([] === $codes) {
            return 0;
        }

        return $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons SET redeemed_order_id = :order, redeemed_at = :now
             WHERE store = :store AND code IN (:codes) AND redeemed_at IS NULL',
            ['order' => $orderId, 'now' => self::now(), 'store' => $store, 'codes' => $codes],
            ['codes' => ArrayParameterType::STRING]
        );
    }

    public function deleteForLead(int $leadId): void
    {
        $this->connection->executeStatement('DELETE FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons WHERE lead_id = ?', [$leadId]);
    }

    public function moveToLead(int $fromLeadId, int $toLeadId): void
    {
        $this->connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons SET lead_id = ? WHERE lead_id = ?', [$toLeadId, $fromLeadId]);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
