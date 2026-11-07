<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

final readonly class StorefrontEventPayload
{
    /**
     * @param list<array<string, mixed>>|null $cartItems
     */
    public function __construct(
        public string $eventType,
        public \DateTimeImmutable $occurredAt,
        public string $source,
        public ?string $productId = null,
        public ?string $productName = null,
        public ?string $category = null,
        public ?string $price = null,
        public ?string $currency = null,
        public ?string $productUrl = null,
        public ?string $imageUrl = null,
        public ?string $cartId = null,
        public ?string $cartValue = null,
        public ?string $cartUrl = null,
        public ?array $cartItems = null,
    ) {
    }
}
