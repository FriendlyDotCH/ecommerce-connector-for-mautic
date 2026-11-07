<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

final readonly class OrderItemPayload
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public int $qty,
        public ?string $category = null,
        public ?string $unitPrice = null,
        public int $refundedQty = 0,
    ) {
    }
}
