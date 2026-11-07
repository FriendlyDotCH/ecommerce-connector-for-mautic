<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;

class EcommerceOrder
{
    public const STATUS_PAID               = 'paid';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED           = 'refunded';
    public const STATUS_CANCELLED          = 'cancelled';

    public const STATUSES = [
        self::STATUS_PAID,
        self::STATUS_PARTIALLY_REFUNDED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Orders that count toward order count, revenue and purchase history.
     */
    public const COUNTED_STATUSES = [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED];

    private int $id;

    private ?Lead $lead = null;

    private ?Email $email = null;

    /**
     * Campaign that sent the attributed email, if any. A plain ID so deleting a campaign
     * leaves revenue history intact.
     */
    private ?int $campaignId = null;

    private string $orderId;

    private string $orderTotal;

    private string $status = self::STATUS_PAID;

    private string $refundedTotal = '0.0000';

    /**
     * Order total minus refunds; 0 for cancelled and fully refunded orders.
     */
    private string $netTotal = '0.0000';

    private ?string $orderCurrency = null;

    private string $orderSource;

    private ?string $location = null;

    /**
     * When the order was placed in the store. dateAdded is when Mautic recorded it.
     */
    private \DateTimeInterface $orderDate;

    private \DateTimeInterface $dateAdded;

    /**
     * @var Collection<int, EcommerceOrderItem>
     */
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ecommerce_orders')
            ->setCustomRepositoryClass(EcommerceOrderRepository::class)
            ->addIndex(['email_id'], 'ecommerce_email_id')
            ->addIndex(['lead_id'], 'ecommerce_lead_id')
            ->addIndex(['order_id'], 'ecommerce_order_id')
            ->addIndex(['date_added'], 'ecommerce_date_added')
            ->addIndex(['lead_id', 'order_date'], 'ecommerce_lead_order_date')
            ->addIndex(['status'], 'ecommerce_status')
            ->addIndex(['campaign_id'], 'ecommerce_campaign_id')
            ->addUniqueConstraint(['order_id', 'order_source'], 'ecommerce_order_source_unique');

        $builder->addId();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->createManyToOne('email', Email::class)
            ->addJoinColumn('email_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addNamedField('campaignId', 'integer', 'campaign_id', true);
        $builder->addNamedField('orderId', 'string', 'order_id');
        $builder->addField('orderTotal', 'decimal', [
            'columnName' => 'order_total',
            'precision'  => 19,
            'scale'      => 4,
        ]);
        $builder->addField('status', 'string', ['length' => 32]);
        $builder->addField('refundedTotal', 'decimal', [
            'columnName' => 'refunded_total',
            'precision'  => 19,
            'scale'      => 4,
        ]);
        $builder->addField('netTotal', 'decimal', [
            'columnName' => 'net_total',
            'precision'  => 19,
            'scale'      => 4,
        ]);
        $builder->addNamedField('orderCurrency', 'string', 'order_currency', true);
        $builder->addNamedField('orderSource', 'string', 'order_source');
        $builder->addNamedField('location', 'string', 'location', true);
        $builder->addNamedField('orderDate', 'datetime', 'order_date');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');

        $builder->createOneToMany('items', EcommerceOrderItem::class)
            ->setIndexBy('id')
            ->mappedBy('ecommerceOrder')
            ->cascadePersist()
            ->cascadeRemove()
            ->fetchExtraLazy()
            ->build();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function setEmail(?Email $email): void
    {
        $this->email = $email;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }

    public function setCampaignId(?int $campaignId): void
    {
        $this->campaignId = $campaignId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderTotal(): string
    {
        return $this->orderTotal;
    }

    public function setOrderTotal(string $orderTotal): void
    {
        $this->orderTotal = $orderTotal;
        $this->applyStatus($this->status, $this->refundedTotal);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRefundedTotal(): string
    {
        return $this->refundedTotal;
    }

    public function getNetTotal(): string
    {
        return $this->netTotal;
    }

    public function isCounted(): bool
    {
        return in_array($this->status, self::COUNTED_STATUSES, true);
    }

    /**
     * Sets status and refunds together and derives the net total. A refund amount decides
     * between partly and fully refunded; "refunded" without an amount refunds the full total.
     */
    public function applyStatus(?string $status, ?string $refundedTotal): void
    {
        $status   = in_array($status, self::STATUSES, true) ? $status : self::STATUS_PAID;
        $total    = (float) ($this->orderTotal ?? 0);
        $refunded = min(max((float) $refundedTotal, 0.0), $total);

        if (self::STATUS_REFUNDED === $status && $refunded <= 0.0) {
            $refunded = $total;
        }

        if (self::STATUS_CANCELLED !== $status && $refunded > 0.0) {
            $status = $refunded >= $total ? self::STATUS_REFUNDED : self::STATUS_PARTIALLY_REFUNDED;
        }

        if (self::STATUS_PARTIALLY_REFUNDED === $status && $refunded <= 0.0) {
            $status = self::STATUS_PAID;
        }

        $this->status        = $status;
        $this->refundedTotal = number_format($refunded, 4, '.', '');
        $this->netTotal      = self::STATUS_CANCELLED === $status
            ? '0.0000'
            : number_format(max($total - $refunded, 0.0), 4, '.', '');
    }

    public function getOrderCurrency(): ?string
    {
        return $this->orderCurrency;
    }

    public function setOrderCurrency(?string $orderCurrency): void
    {
        $this->orderCurrency = $orderCurrency;
    }

    public function getOrderSource(): string
    {
        return $this->orderSource;
    }

    public function setOrderSource(string $orderSource): void
    {
        $this->orderSource = $orderSource;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function getOrderDate(): \DateTimeInterface
    {
        return $this->orderDate;
    }

    public function setOrderDate(\DateTimeInterface $orderDate): void
    {
        $this->orderDate = $orderDate;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(\DateTimeInterface $dateAdded): void
    {
        $this->dateAdded = $dateAdded;
    }

    /**
     * @return Collection<int, EcommerceOrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(EcommerceOrderItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setEcommerceOrder($this);
        }
    }
}
