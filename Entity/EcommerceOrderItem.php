<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Lead;

class EcommerceOrderItem
{
    private int $id;

    private EcommerceOrder $ecommerceOrder;

    private ?Lead $lead = null;

    private string $itemId;

    private string $itemName;

    private int $qty;

    private ?string $category = null;

    private ?string $unitPrice = null;

    private int $refundedQty = 0;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ecommerce_order_items')
            ->setCustomRepositoryClass(EcommerceOrderItemRepository::class)
            ->addIndex(['lead_id'], 'ecommerce_item_lead_id')
            ->addIndex(['item_id'], 'ecommerce_item_item_id')
            ->addIndex(['item_name'], 'ecommerce_item_item_name')
            ->addIndex(['category'], 'ecommerce_item_category');

        $builder->addId();

        $builder->createManyToOne('ecommerceOrder', EcommerceOrder::class)
            ->inversedBy('items')
            ->addJoinColumn('order_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->addNamedField('itemId', 'string', 'item_id');
        $builder->addNamedField('itemName', 'string', 'item_name');
        $builder->addField('qty', 'integer');
        $builder->addNamedField('category', 'string', 'category', true);
        $builder->addField('unitPrice', 'decimal', [
            'columnName' => 'unit_price',
            'precision'  => 19,
            'scale'      => 4,
            'nullable'   => true,
        ]);
        $builder->addNamedField('refundedQty', 'integer', 'refunded_qty');
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEcommerceOrder(): EcommerceOrder
    {
        return $this->ecommerceOrder;
    }

    public function setEcommerceOrder(EcommerceOrder $ecommerceOrder): void
    {
        $this->ecommerceOrder = $ecommerceOrder;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function getItemId(): string
    {
        return $this->itemId;
    }

    public function setItemId(string $itemId): void
    {
        $this->itemId = $itemId;
    }

    public function getItemName(): string
    {
        return $this->itemName;
    }

    public function setItemName(string $itemName): void
    {
        $this->itemName = $itemName;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function setQty(int $qty): void
    {
        $this->qty = $qty;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?string $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function getRefundedQty(): int
    {
        return $this->refundedQty;
    }

    public function setRefundedQty(int $refundedQty): void
    {
        $this->refundedQty = min(max($refundedQty, 0), $this->qty ?? 0);
    }
}
