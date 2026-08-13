<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

final class LocationFormatter
{
    public function format(?string $location): ?string
    {
        if (null === $location) {
            return null;
        }

        $normalized = trim(preg_replace('/[-_]+/', ' ', strtolower(trim($location))) ?? '');
        if ('' === $normalized) {
            return null;
        }

        return ucfirst($normalized);
    }
}
