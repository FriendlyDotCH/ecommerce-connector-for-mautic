<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DTO;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;

/**
 * A detected catalog change, passed to the product alert decisions.
 */
final class ProductChange
{
    /** Set by the decision when the contact matched one of its campaign events. */
    public bool $matched = false;

    public function __construct(
        public readonly int $id,
        public readonly string $store,
        public readonly string $externalId,
        public readonly string $type,
        public readonly ?string $oldValue,
        public readonly ?string $newValue,
        public readonly EcommerceProduct $product,
    ) {
    }
}
