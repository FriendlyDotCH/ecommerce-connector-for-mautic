<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CoreBundle\Helper\ClickthroughHelper;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;

class OrderPayloadParser
{
    public const ORDER_ID_MAX     = 191;
    public const ORDER_SOURCE_MAX = 64;
    public const LOCATION_MAX     = 191;
    public const ITEM_ID_MAX      = 191;
    public const ITEM_NAME_MAX    = 191;
    public const CATEGORY_MAX     = 191;

    /**
     * How far an order date may lie in the future, to allow for clock skew between store and Mautic.
     */
    public const ORDER_DATE_MAX_FUTURE = '+1 day';

    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function parse(array $input, string $context = 'request'): ?OrderPayload
    {
        if (!isset($input['order_id'])) {
            return null;
        }

        $orderId = $this->sanitizeString((string) $input['order_id'], self::ORDER_ID_MAX);
        if ('' === $orderId) {
            $this->logInvalidPayload($context, 'Missing or empty order_id.');

            return null;
        }

        if (array_key_exists('order_total', $input) && null !== $input['order_total'] && '' !== $input['order_total']) {
            if (!is_numeric($input['order_total'])) {
                $this->logInvalidPayload($context, 'order_total must be numeric.');

                return null;
            }

            $orderTotal = $this->normalizeAmount((string) $input['order_total']);
            if (null === $orderTotal) {
                $this->logInvalidPayload($context, 'order_total must be zero or greater and within configured limits.');

                return null;
            }
        } else {
            $orderTotal = '0.0000';
        }

        $orderSource = isset($input['order_source'])
            ? $this->sanitizeString((string) $input['order_source'], self::ORDER_SOURCE_MAX)
            : Config::DEFAULT_ORDER_SOURCE;
        $orderSource = '' !== $orderSource ? $orderSource : Config::DEFAULT_ORDER_SOURCE;

        if (!$this->isAllowedOrderSource($orderSource)) {
            $this->logInvalidPayload($context, sprintf('order_source "%s" is not allowed.', $orderSource));

            return null;
        }

        $orderCurrency = null;
        if (isset($input['order_currency']) && is_scalar($input['order_currency'])) {
            $currency = strtoupper(trim((string) $input['order_currency']));
            if ('' !== $currency) {
                if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                    $this->logInvalidPayload($context, 'order_currency must be a 3-letter ISO 4217 code.');

                    return null;
                }

                $orderCurrency = $currency;
            }
        }

        if (null === $orderCurrency) {
            $orderCurrency = $this->config->getDefaultCurrency();
        }

        $emailId = isset($input['email_id']) ? (int) $input['email_id'] : 0;
        $clickthrough = null;

        if (!$emailId && isset($input['ct'])) {
            $clickthrough = is_array($input['ct'])
                ? $input['ct']
                : ClickthroughHelper::decodeArrayFromUrl((string) $input['ct']);

            if (is_array($clickthrough) && isset($clickthrough['email'])) {
                $emailId = (int) $clickthrough['email'];
            }
        }

        $orderDate = null;
        if (isset($input['order_date']) && '' !== $input['order_date']) {
            $orderDate = $this->parseOrderDate($input['order_date']);
            if (null === $orderDate) {
                $this->logInvalidPayload($context, 'order_date must be an ISO 8601 date or a Unix timestamp, and not in the future.');

                return null;
            }
        }

        $location = $this->optionalSanitizedString($input['location'] ?? null, self::LOCATION_MAX);
        $items    = $this->parseItems($input['items'] ?? null, $context);

        return new OrderPayload(
            $orderId,
            $orderTotal,
            $orderSource,
            $orderCurrency,
            $emailId > 0 ? $emailId : null,
            is_array($clickthrough) ? $clickthrough : null,
            $location,
            $items,
            $orderDate,
        );
    }

    private function parseOrderDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        try {
            $date = ctype_digit($value)
                ? new \DateTimeImmutable('@'.$value)
                : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        if ($date > new \DateTimeImmutable(self::ORDER_DATE_MAX_FUTURE)) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return list<OrderItemPayload>
     */
    private function parseItems(mixed $items, string $context): array
    {
        if (null === $items || '' === $items) {
            return [];
        }

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (!is_array($decoded)) {
                $this->logInvalidPayload($context, 'items must be a JSON array.');

                return [];
            }
            $items = $decoded;
        }

        if (!is_array($items)) {
            return [];
        }

        $parsed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId   = $this->sanitizeString((string) ($item['item_id'] ?? ''), self::ITEM_ID_MAX);
            $itemName = $this->sanitizeString((string) ($item['item_name'] ?? ''), self::ITEM_NAME_MAX);
            $qty      = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 0;

            if ('' === $itemId || '' === $itemName || $qty < 1) {
                continue;
            }

            $category  = $this->optionalSanitizedString($item['category'] ?? null, self::CATEGORY_MAX);
            $unitPrice = $this->parseUnitPrice($item['unit_price'] ?? $item['price'] ?? null);

            $parsed[] = new OrderItemPayload($itemId, $itemName, $qty, $category, $unitPrice);
        }

        return $parsed;
    }

    /**
     * An unusable unit price is dropped rather than rejecting the whole order.
     */
    private function parseUnitPrice(mixed $value): ?string
    {
        if (!is_scalar($value) || '' === $value || !is_numeric($value)) {
            return null;
        }

        return $this->normalizeAmount((string) $value);
    }

    private function normalizeAmount(string $amount): ?string
    {
        if (!is_finite((float) $amount)) {
            return null;
        }

        $value = round((float) $amount, 4);
        if ($value < 0 || $value > $this->config->getMaxOrderTotal()) {
            return null;
        }

        return number_format($value, 4, '.', '');
    }

    private function isAllowedOrderSource(string $orderSource): bool
    {
        $allowedSources = $this->config->getAllowedOrderSources();
        if ([] === $allowedSources) {
            return true;
        }

        foreach ($allowedSources as $allowedSource) {
            if (strcasecmp($orderSource, $allowedSource) === 0) {
                return true;
            }
        }

        return false;
    }

    private function optionalSanitizedString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $sanitized = $this->sanitizeString((string) $value, $maxLength);

        return '' !== $sanitized ? $sanitized : null;
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        $value = trim($value);

        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function logInvalidPayload(string $context, string $reason): void
    {
        $this->logger->debug(
            sprintf('Ecommerce order payload rejected (%s): %s', $context, $reason),
            ['plugin' => 'EcommerceConnector']
        );
    }
}
