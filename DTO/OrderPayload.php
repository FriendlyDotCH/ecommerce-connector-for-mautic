<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

final readonly class OrderPayload
{
    /**
     * @param array<string, mixed>|null $clickthrough
     * @param list<OrderItemPayload>    $items
     */
    public function __construct(
        public string $orderId,
        public string $orderTotal,
        public string $orderSource,
        public ?string $orderCurrency,
        public ?int $emailId,
        public ?array $clickthrough,
        public ?string $location = null,
        public array $items = [],
        public ?\DateTimeImmutable $orderDate = null,
        /** One of EcommerceOrder::STATUSES, or null when the sender did not say. */
        public ?string $status = null,
        public ?string $refundedTotal = null,
    ) {
    }
}
