<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Symfony\Component\HttpFoundation\Request;

class WebhookSignatureValidator
{
    public const SIGNATURE_HEADER = 'X-Mautic-Ecommerce-Signature';

    public function isValid(Request $request, string $secret): bool
    {
        $providedSignature = $request->headers->get(self::SIGNATURE_HEADER);
        if (!is_string($providedSignature) || '' === $providedSignature) {
            $providedSignature = $request->request->get('signature');
        }

        if (!is_string($providedSignature) || '' === $providedSignature) {
            return false;
        }

        $payload = $this->buildPayload($request);
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    private function buildPayload(Request $request): string
    {
        if (WebhookRequestBody::isJson($request)) {
            return $request->getContent();
        }

        $data = array_merge($request->query->all(), $request->request->all());
        unset($data['signature']);

        ksort($data);

        return http_build_query($data);
    }
}
