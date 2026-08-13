<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Symfony\Component\HttpFoundation\Request;

final class WebhookRequestBody
{
    public static function isJson(Request $request): bool
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        $content = ltrim($request->getContent());

        return '' !== $content && ('{' === $content[0] || '[' === $content[0]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getInput(Request $request): array
    {
        if (self::isJson($request)) {
            $decoded = json_decode($request->getContent(), true);
            if (!is_array($decoded)) {
                return $request->query->all();
            }

            return array_merge($request->query->all(), $decoded);
        }

        return array_merge($request->query->all(), $request->request->all());
    }
}
