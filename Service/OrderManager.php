<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItem;

/**
 * Changes to existing orders. New orders go through EcommerceOrderTracker. Edits and deletes
 * re-sync the affected contacts but never trigger campaigns.
 */
class OrderManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeadCommerceFieldUpdater $leadCommerceFieldUpdater,
        private BehaviourCalculator $behaviourCalculator,
        private LeadModel $leadModel,
    ) {
    }

    /**
     * Replaces the order's data with the payload. Order ID and source stay as they are.
     */
    public function update(EcommerceOrder $order, OrderPayload $payload, ?Lead $lead): EcommerceOrder
    {
        $previousLead = $order->getLead();

        $order->setOrderTotal($payload->orderTotal);
        $order->applyStatus($payload->status ?? $order->getStatus(), $payload->refundedTotal ?? $order->getRefundedTotal());
        $order->setOrderCurrency($payload->orderCurrency);
        $order->setLocation($payload->location);
        if (null !== $payload->orderDate) {
            $order->setOrderDate(\DateTime::createFromImmutable($payload->orderDate));
        }
        $order->setLead($lead);

        if (null !== $payload->emailId && $payload->emailId !== $order->getEmail()?->getId()) {
            $order->setEmail($this->entityManager->getRepository(Email::class)->find($payload->emailId));
        }

        foreach ($order->getItems() as $item) {
            $order->getItems()->removeElement($item);
            $this->entityManager->remove($item);
        }

        foreach ($payload->items as $itemPayload) {
            $item = new EcommerceOrderItem();
            $item->setItemId($itemPayload->itemId);
            $item->setItemName($itemPayload->itemName);
            $item->setQty($itemPayload->qty);
            $item->setCategory($itemPayload->category);
            $item->setUnitPrice($itemPayload->unitPrice);
            $item->setRefundedQty($itemPayload->refundedQty);
            $item->setLead($lead);
            $order->addItem($item);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->resync([$previousLead, $lead]);

        return $order;
    }

    /**
     * Applies status, refunded total and per-item refunded quantities (matched by item ID) from
     * a re-sent order. Everything else on the order stays as first recorded.
     *
     * @return bool whether anything changed
     */
    public function applyRefunds(EcommerceOrder $order, OrderPayload $payload): bool
    {
        $before = [$order->getStatus(), $order->getRefundedTotal()];
        $order->applyStatus($payload->status ?? $order->getStatus(), $payload->refundedTotal ?? $order->getRefundedTotal());
        $changed = $before !== [$order->getStatus(), $order->getRefundedTotal()];

        $refundedQty = [];
        foreach ($payload->items as $itemPayload) {
            $refundedQty[$itemPayload->itemId] = $itemPayload->refundedQty;
        }

        foreach ($order->getItems() as $item) {
            // A fully refunded or cancelled order refunds every item.
            $qty = in_array($order->getStatus(), EcommerceOrder::COUNTED_STATUSES, true)
                ? ($refundedQty[$item->getItemId()] ?? $item->getRefundedQty())
                : $item->getQty();

            if ($qty !== $item->getRefundedQty()) {
                $item->setRefundedQty($qty);
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->resync([$order->getLead()]);

        return true;
    }

    public function delete(EcommerceOrder $order): void
    {
        $lead = $order->getLead();

        $this->entityManager->remove($order);
        $this->entityManager->flush();

        $this->resync([$lead]);
    }

    /**
     * @param array<Lead|null> $leads
     */
    private function resync(array $leads): void
    {
        $done = [];
        foreach ($leads as $lead) {
            $leadId = $lead?->getId();
            if (null === $lead || null === $leadId || isset($done[$leadId])) {
                continue;
            }

            $done[$leadId] = true;

            // An order's contact can be a proxy without custom field values; load it fully.
            $fresh = $this->leadModel->getEntity($leadId);
            if ($fresh instanceof Lead) {
                $this->leadCommerceFieldUpdater->syncForLead($fresh);
            }
            $this->behaviourCalculator->recalculateForLead((int) $leadId);
        }
    }
}
