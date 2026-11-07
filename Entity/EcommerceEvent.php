<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Lead;

/**
 * A storefront event of an identified contact: viewed a product, added to cart or started
 * checkout. Kept for a limited time (see the retention setting).
 */
class EcommerceEvent
{
    public const TYPE_VIEWED_PRODUCT   = 'viewed_product';
    public const TYPE_ADDED_TO_CART    = 'added_to_cart';
    public const TYPE_STARTED_CHECKOUT = 'started_checkout';

    public const TYPES = [self::TYPE_VIEWED_PRODUCT, self::TYPE_ADDED_TO_CART, self::TYPE_STARTED_CHECKOUT];

    private ?int $id = null;

    private Lead $lead;

    private string $eventType;

    private ?string $productId = null;

    private ?string $productName = null;

    private ?string $category = null;

    private ?string $price = null;

    private ?string $currency = null;

    private ?string $productUrl = null;

    private ?string $imageUrl = null;

    private ?string $cartId = null;

    private ?string $cartValue = null;

    private ?string $cartUrl = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $cartItems = null;

    private string $source = 'web';

    private \DateTimeInterface $occurredAt;

    /**
     * Set once an abandonment decision has fired for this event.
     */
    private ?\DateTimeInterface $abandonedAt = null;

    private \DateTimeInterface $dateAdded;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ecommerce_events')
            ->setCustomRepositoryClass(EcommerceEventRepository::class)
            ->addIndex(['lead_id', 'event_type', 'occurred_at'], 'ecommerce_event_lead_type_date')
            ->addIndex(['event_type', 'occurred_at'], 'ecommerce_event_type_date')
            ->addIndex(['occurred_at'], 'ecommerce_event_occurred_at');

        $builder->addId();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->addField('eventType', 'string', ['columnName' => 'event_type', 'length' => 32]);
        $builder->addNullableField('productId', 'string', 'product_id');
        $builder->addNullableField('productName', 'string', 'product_name');
        $builder->addNullableField('category', 'string', 'category');
        $builder->addField('price', 'decimal', ['precision' => 19, 'scale' => 4, 'nullable' => true]);
        $builder->addField('currency', 'string', ['length' => 3, 'nullable' => true]);
        $builder->addNullableField('productUrl', 'text', 'product_url');
        $builder->addNullableField('imageUrl', 'text', 'image_url');
        $builder->addNullableField('cartId', 'string', 'cart_id');
        $builder->addField('cartValue', 'decimal', ['columnName' => 'cart_value', 'precision' => 19, 'scale' => 4, 'nullable' => true]);
        $builder->addNullableField('cartUrl', 'text', 'cart_url');
        $builder->addNullableField('cartItems', 'json', 'cart_items');
        $builder->addField('source', 'string', ['length' => 64]);
        $builder->addNamedField('occurredAt', 'datetime', 'occurred_at');
        $builder->addNamedField('abandonedAt', 'datetime', 'abandoned_at', true);
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function setLead(Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): void
    {
        $this->productName = $productName;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): void
    {
        $this->price = $price;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getProductUrl(): ?string
    {
        return $this->productUrl;
    }

    public function setProductUrl(?string $productUrl): void
    {
        $this->productUrl = $productUrl;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    public function getCartId(): ?string
    {
        return $this->cartId;
    }

    public function setCartId(?string $cartId): void
    {
        $this->cartId = $cartId;
    }

    public function getCartValue(): ?string
    {
        return $this->cartValue;
    }

    public function setCartValue(?string $cartValue): void
    {
        $this->cartValue = $cartValue;
    }

    public function getCartUrl(): ?string
    {
        return $this->cartUrl;
    }

    public function setCartUrl(?string $cartUrl): void
    {
        $this->cartUrl = $cartUrl;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getCartItems(): ?array
    {
        return $this->cartItems;
    }

    /**
     * @param list<array<string, mixed>>|null $cartItems
     */
    public function setCartItems(?array $cartItems): void
    {
        $this->cartItems = $cartItems;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getOccurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeInterface $occurredAt): void
    {
        $this->occurredAt = $occurredAt;
    }

    public function getAbandonedAt(): ?\DateTimeInterface
    {
        return $this->abandonedAt;
    }

    public function setAbandonedAt(?\DateTimeInterface $abandonedAt): void
    {
        $this->abandonedAt = $abandonedAt;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(\DateTimeInterface $dateAdded): void
    {
        $this->dateAdded = $dateAdded;
    }
}
