<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Coupon;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MauticPlugin\EcommerceConnectorBundle\Exception\CouponException;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;

/**
 * Issues a single-use coupon to one contact: generates the code, has the shop create it
 * (limited to the contact's email, one use) and records it once the shop confirmed.
 */
class CouponIssuer
{
    public const TYPE_PERCENT    = 'percent';
    public const TYPE_FIXED_CART = 'fixed_cart';

    public const DEFAULT_VALID_DAYS = 14;

    /** No 0/O or 1/I/L: codes are read off screens and typed in. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    public function __construct(
        private CouponGateway $gateway,
        private CouponStore $store,
        private Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $settings the action's properties
     *
     * @return array<string, mixed> the coupon row (new, or the open one this action issued before)
     *
     * @throws CouponException
     */
    public function issue(int $leadId, string $email, ?int $campaignId, ?int $campaignEventId, array $settings): array
    {
        if (null !== $campaignEventId && null !== ($open = $this->store->findOpenForEvent($leadId, $campaignEventId))) {
            return $open;
        }

        $type = self::TYPE_FIXED_CART === ($settings['discount_type'] ?? null) ? self::TYPE_FIXED_CART : self::TYPE_PERCENT;
        $amount = (float) ($settings['amount'] ?? 0);
        if ($amount <= 0 || (self::TYPE_PERCENT === $type && $amount > 100)) {
            throw new CouponException('The coupon amount must be above 0 (and at most 100 for a percentage).', false);
        }

        $days     = max(1, min(365, (int) ($settings['valid_days'] ?? self::DEFAULT_VALID_DAYS) ?: self::DEFAULT_VALID_DAYS));
        // Valid through the last day; the shop applies its own timezone to the date.
        $expires  = (new \DateTimeImmutable(sprintf('+%d days', $days), new \DateTimeZone('UTC')))->setTime(23, 59, 59);
        $minimum  = isset($settings['minimum_amount']) && is_numeric($settings['minimum_amount']) && (float) $settings['minimum_amount'] > 0 ? (float) $settings['minimum_amount'] : null;
        $currency = self::TYPE_FIXED_CART === $type ? $this->config->getDefaultCurrency() : null;

        for ($attempt = 1; ; ++$attempt) {
            $code    = self::generateCode((string) ($settings['prefix'] ?? ''));
            $request = [
                'code'               => $code,
                'email'              => $email,
                'discount_type'      => $type,
                'amount'             => number_format($amount, 2, '.', ''),
                'expires_at'         => $expires->format('Y-m-d'),
                'minimum_amount'     => null !== $minimum ? number_format($minimum, 2, '.', '') : null,
                'exclude_sale_items' => !empty($settings['exclude_sale_items']),
                'free_shipping'      => !empty($settings['free_shipping']),
                'description'        => null !== $campaignId ? sprintf('Mautic campaign %d', $campaignId) : 'Mautic',
            ];

            $storeName = $this->gateway->create($request);

            $row = [
                'lead_id'           => $leadId,
                'campaign_id'       => $campaignId,
                'campaign_event_id' => $campaignEventId,
                'store'             => $storeName,
                'code'              => $code,
                'discount_type'     => $type,
                'amount'            => number_format($amount, 4, '.', ''),
                'currency'          => $currency,
                'minimum_amount'    => null !== $minimum ? number_format($minimum, 4, '.', '') : null,
                'expires_at'        => $expires->format('Y-m-d H:i:s'),
            ];

            try {
                $this->store->insert($row);

                return $row;
            } catch (UniqueConstraintViolationException $exception) {
                // 31^8 codes per prefix make this practically impossible; never loop for ever.
                if ($attempt >= 3) {
                    throw new CouponException('Could not record a unique coupon code.');
                }
            }
        }
    }

    public static function generateCode(string $prefix = ''): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');
        $code   = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return ('' !== $prefix ? mb_substr($prefix, 0, 12).'-' : '').$code;
    }
}
