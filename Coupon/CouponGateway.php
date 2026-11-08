<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Coupon;

use MauticPlugin\EcommerceConnectorBundle\Exception\CouponException;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the shop to create a coupon: a signed POST to the shop's coupon endpoint (the WooCommerce
 * connector's /wp-json/mautic-ecommerce/v1/coupon), with the same secret and signature header as
 * the shop's requests to Mautic. The body carries a timestamp, so a captured request cannot be
 * replayed later.
 */
class CouponGateway
{
    public const SIGNATURE_HEADER = 'X-Mautic-Ecommerce-Signature';

    public const TIMEOUT = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private Config $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->config->getCouponUrl() && null !== $this->config->getWebhookSecret();
    }

    /**
     * @param array<string, mixed> $coupon code, email, discount_type, amount, expires_at, ...
     *
     * @return string the store name the shop answered with
     *
     * @throws CouponException
     */
    public function create(array $coupon): string
    {
        $url    = $this->config->getCouponUrl();
        $secret = $this->config->getWebhookSecret();
        if (null === $url || null === $secret) {
            throw new CouponException('No shop coupon endpoint or webhook secret is set in the Ecommerce Connector settings.', false);
        }

        $body = json_encode($coupon + ['timestamp' => time()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'body'          => $body,
                'headers'       => [
                    'Content-Type'         => 'application/json',
                    'Accept'               => 'application/json',
                    self::SIGNATURE_HEADER => hash_hmac('sha256', $body, $secret),
                ],
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $status  = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new CouponException('The shop could not be reached: '.$exception->getMessage());
        }

        $answer = json_decode($content, true);
        $answer = is_array($answer) ? $answer : [];

        if ($status >= 200 && $status < 300 && true === ($answer['ok'] ?? null)) {
            if (strcasecmp((string) ($answer['code'] ?? ''), (string) $coupon['code']) !== 0) {
                throw new CouponException('The shop answered with another coupon code.', false);
            }

            $store = is_string($answer['store'] ?? null) ? strtolower(trim($answer['store'])) : '';

            return '' !== $store ? mb_substr($store, 0, 64) : 'woocommerce';
        }

        $reason = is_string($answer['message'] ?? null) ? mb_substr($answer['message'], 0, 300) : 'no details';

        // 4xx: the shop refused this request (signature, switched off, invalid); retrying the same will not help.
        throw new CouponException(sprintf('The shop refused the coupon (HTTP %d: %s).', $status, $reason), $status >= 500 || 0 === $status || 429 === $status);
    }
}
