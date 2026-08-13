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
    public const MERCHANT_MAX     = 191;
    public const LOCATION_MAX     = 191;
    public const ITEM_ID_MAX      = 191;
    public const ITEM_NAME_MAX    = 191;
    public const CATEGORY_MAX     = 191;

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

        $merchant = $this->optionalSanitizedString($input['merchant'] ?? null, self::MERCHANT_MAX);
        $location = $this->optionalSanitizedString($input['location'] ?? null, self::LOCATION_MAX);
        $items    = $this->parseItems($input['items'] ?? null, $context);

        return new OrderPayload(
            $orderId,
            $orderTotal,
            $orderSource,
            $orderCurrency,
            $emailId > 0 ? $emailId : null,
            is_array($clickthrough) ? $clickthrough : null,
            $merchant,
            $location,
            $items,
        );
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

            $category = $this->optionalSanitizedString($item['category'] ?? null, self::CATEGORY_MAX);

            $parsed[] = new OrderItemPayload($itemId, $itemName, $qty, $category);
        }

        return $parsed;
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

        return in_array($orderSource, $allowedSources, true);
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
