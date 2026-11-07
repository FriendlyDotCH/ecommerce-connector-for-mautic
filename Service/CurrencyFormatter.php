<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;

final class CurrencyFormatter
{
    /**
     * Currencies shown without decimals, whatever ISO 4217 says (forint prices are whole).
     */
    public const WHOLE_NUMBER_CURRENCIES = ['HUF', 'JPY', 'KRW', 'ISK', 'CLP', 'VND', 'TWD', 'PYG', 'UGX', 'XAF', 'XOF'];

    public function __construct(
        private ?Config $config = null,
    ) {
    }

    /**
     * Locale order: the argument, the plugin's money format locale, the request or system
     * locale, en_US. Command-line runs (cron, email sending) usually have no locale, so set
     * the money format locale for consistent amounts in emails.
     */
    public function format(string $currencyCode, float|string $amount, ?string $locale = null): string
    {
        $currencyCode = strtoupper($currencyCode);
        $locale       = $locale ?: ($this->config?->getMoneyLocale() ?: self::defaultLocale());
        $formatter    = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        if (in_array($currencyCode, self::WHOLE_NUMBER_CURRENCIES, true)) {
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
        }

        $formatted = $formatter->formatCurrency((float) $amount, $currencyCode);

        if (false !== $formatted) {
            return $formatted;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private static function defaultLocale(): string
    {
        $locale = \Locale::getDefault();

        return '' !== $locale && !in_array($locale, ['C', 'POSIX', 'en_US_POSIX'], true) ? $locale : 'en_US';
    }
}
