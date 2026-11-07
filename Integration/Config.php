<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Integration;

use Mautic\PluginBundle\Helper\IntegrationHelper;

class Config
{
    public const DEFAULT_CURRENCY              = 'USD';
    public const DEFAULT_MAX_TOTAL             = 1000000.0;
    public const DEFAULT_ORDER_SOURCE          = 'web';
    public const DEFAULT_ENABLE_PAGE_HIT_TRACKING = true;

    /**
     * Lifecycle stage thresholds; see LifecycleCalculator.
     */
    public const LIFECYCLE_DEFAULTS = [
        'lifecycle_lapsed_days'        => 365,
        'lifecycle_at_risk_factor'     => 1.5,
        'lifecycle_at_risk_min_days'   => 30,
        'lifecycle_single_order_days'  => 90,
        'lifecycle_vip_top_percent'    => 10,
        'lifecycle_vip_min_orders'     => 5,
    ];

    public const DEFAULT_EVENT_RETENTION_DAYS = 90;

    /**
     * Minutes after the last cart, checkout or product view event before it counts as abandoned.
     */
    public const DEFAULT_CART_ABANDON_MINUTES   = 60;
    public const DEFAULT_BROWSE_ABANDON_MINUTES = 120;

    public const ON_CONTACT_DELETE_DELETE    = 'delete';
    public const ON_CONTACT_DELETE_ANONYMIZE = 'anonymize';

    public function __construct(
        private IntegrationHelper $integrationHelper,
    ) {
    }

    public function isPublished(): bool
    {
        $integration = $this->getIntegrationObject();

        return null !== $integration && $integration->getIntegrationSettings()->getIsPublished();
    }

    public function getWebhookSecret(): ?string
    {
        $integration = $this->getIntegrationObject();
        if (null === $integration) {
            return null;
        }

        $keys = $integration->getKeys();
        $secret = isset($keys['webhook_secret']) ? trim((string) $keys['webhook_secret']) : '';

        return '' !== $secret ? $secret : null;
    }

    public function isPageHitTrackingEnabled(): bool
    {
        return $this->getFeatureSetting('enable_page_hit_tracking', self::DEFAULT_ENABLE_PAGE_HIT_TRACKING);
    }

    public function isAttributeLastEmailEnabled(): bool
    {
        return $this->getFeatureSetting('attribute_last_email', false);
    }

    /**
     * @return string[]
     */
    public function getAllowedOrderSources(): array
    {
        $integration = $this->getIntegrationObject();
        if (null === $integration) {
            return [];
        }

        $raw = $integration->getIntegrationSettings()->getFeatureSettings()['allowed_order_sources'] ?? '';
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $sources = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($sources, static fn (string $source): bool => '' !== $source));
    }

    public function getDefaultCurrency(): string
    {
        $integration = $this->getIntegrationObject();
        if (null === $integration) {
            return self::DEFAULT_CURRENCY;
        }

        $currency = $integration->getIntegrationSettings()->getFeatureSettings()['default_currency'] ?? self::DEFAULT_CURRENCY;
        $currency = strtoupper(trim((string) $currency));

        return 3 === strlen($currency) ? $currency : self::DEFAULT_CURRENCY;
    }

    /**
     * Currency for lifetime value, behaviours, lifecycle and dashboards. Defaults to the
     * default currency.
     */
    public function getBaseCurrency(): string
    {
        $integration = $this->getIntegrationObject();
        $currency    = null !== $integration
            ? strtoupper(trim((string) ($integration->getIntegrationSettings()->getFeatureSettings()['base_currency'] ?? '')))
            : '';

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : $this->getDefaultCurrency();
    }

    /**
     * Manual rates such as "EUR=395.5, USD=360": base currency units per unit of each currency.
     *
     * @return array<string, float>
     */
    public function getManualExchangeRates(): array
    {
        $integration = $this->getIntegrationObject();
        $raw         = null !== $integration ? (string) ($integration->getIntegrationSettings()->getFeatureSettings()['exchange_rates'] ?? '') : '';
        $rates       = [];

        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $pair) {
            if (1 === preg_match('/^\s*([A-Za-z]{3})\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $pair, $match)) {
                $rate = (float) str_replace(',', '.', $match[2]);
                if ($rate > 0) {
                    $rates[strtoupper($match[1])] = $rate;
                }
            }
        }

        return $rates;
    }

    public function isEcbExchangeRatesEnabled(): bool
    {
        return $this->getFeatureSetting('exchange_rates_ecb', false);
    }

    public function getMaxOrderTotal(): float
    {
        $integration = $this->getIntegrationObject();
        if (null === $integration) {
            return self::DEFAULT_MAX_TOTAL;
        }

        $max = $integration->getIntegrationSettings()->getFeatureSettings()['max_order_total'] ?? self::DEFAULT_MAX_TOTAL;

        return is_numeric($max) ? (float) $max : self::DEFAULT_MAX_TOTAL;
    }

    /**
     * What happens to a contact's orders when the contact is deleted: delete them (default)
     * or keep them without the link to the contact.
     */
    public function getOnContactDelete(): string
    {
        $integration = $this->getIntegrationObject();
        $value       = null !== $integration
            ? ($integration->getIntegrationSettings()->getFeatureSettings()['on_contact_delete'] ?? null)
            : null;

        return self::ON_CONTACT_DELETE_ANONYMIZE === $value ? self::ON_CONTACT_DELETE_ANONYMIZE : self::ON_CONTACT_DELETE_DELETE;
    }

    /**
     * @return array<string, float> every lifecycle setting, defaults filled in
     */
    public function getLifecycleSettings(): array
    {
        $integration = $this->getIntegrationObject();
        $settings    = null !== $integration ? $integration->getIntegrationSettings()->getFeatureSettings() : [];
        $values      = [];

        foreach (self::LIFECYCLE_DEFAULTS as $key => $default) {
            $value        = $settings[$key] ?? null;
            $values[$key] = is_numeric($value) && (float) $value >= 0 ? (float) $value : (float) $default;
        }

        return $values;
    }

    /**
     * Storefront events are off until switched on: they need tracking consent on the store.
     */
    public function isStorefrontEventsEnabled(): bool
    {
        return $this->getFeatureSetting('enable_storefront_events', false);
    }

    public function getEventRetentionDays(): int
    {
        return $this->getPositiveInt('event_retention_days', self::DEFAULT_EVENT_RETENTION_DAYS);
    }

    public function getCartAbandonMinutes(): int
    {
        return $this->getPositiveInt('cart_abandon_minutes', self::DEFAULT_CART_ABANDON_MINUTES);
    }

    public function getBrowseAbandonMinutes(): int
    {
        return $this->getPositiveInt('browse_abandon_minutes', self::DEFAULT_BROWSE_ABANDON_MINUTES);
    }

    private function getPositiveInt(string $key, int $default): int
    {
        $integration = $this->getIntegrationObject();
        $value       = null !== $integration ? ($integration->getIntegrationSettings()->getFeatureSettings()[$key] ?? null) : null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function getFeatureSetting(string $key, bool $default): bool
    {
        $integration = $this->getIntegrationObject();
        if (null === $integration) {
            return $default;
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        if (!array_key_exists($key, $settings)) {
            return $default;
        }

        return (bool) $settings[$key];
    }

    private function getIntegrationObject(): ?EcommerceConnectorIntegration
    {
        $integration = $this->integrationHelper->getIntegrationObject(EcommerceConnectorIntegration::NAME);

        return $integration instanceof EcommerceConnectorIntegration ? $integration : null;
    }
}
