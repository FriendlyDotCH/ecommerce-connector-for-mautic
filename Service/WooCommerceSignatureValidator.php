<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * WooCommerce signs the raw body with HMAC-SHA256 and the webhook's secret, base64-encoded.
 */
class WooCommerceSignatureValidator
{
    public const SIGNATURE_HEADER = 'X-WC-Webhook-Signature';

    public function isValid(Request $request, string $secret): bool
    {
        $provided = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        return '' !== $provided
            && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $provided);
    }
}
