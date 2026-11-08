<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Exception;

/**
 * The shop did not create the coupon. $retryable is false when trying again cannot help
 * (not configured, refused by the shop).
 */
final class CouponException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
