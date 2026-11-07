<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItem;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Event\EcommerceOrderEvent;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EcommerceOrderTracker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EcommerceOrderRepository $orderRepository,
        private Config $config,
        private LoggerInterface $logger,
        private LeadCommerceFieldUpdater $leadCommerceFieldUpdater,
        private EventDispatcherInterface $dispatcher,
        private OrderManager $orderManager,
        private CampaignAttributor $campaignAttributor,
        private ExchangeRateProvider $exchangeRateProvider,
        private EcommerceProductRepository $productRepository,
    ) {
    }

    public function recordOrder(
        OrderPayload $payload,
        ?Lead $lead,
        ?Email $email = null,
    ): ?EcommerceOrder {
        if ($this->orderRepository->orderExists($payload->orderId, $payload->orderSource)) {
            $this->handleKnownOrder($payload);

            return null;
        }

        if (null === $email && null !== $lead && $this->config->isAttributeLastEmailEnabled()) {
            $email = $this->resolveEmailFromLead($lead);
        }

        if (null === $email && null !== $payload->emailId) {
            $email = $this->entityManager->getRepository(Email::class)->find($payload->emailId);
        }

        $order = new EcommerceOrder();
        $order->setOrderId($payload->orderId);
        $order->setOrderTotal($payload->orderTotal);
        $order->applyStatus($payload->status, $payload->refundedTotal);
        $order->setOrderCurrency($payload->orderCurrency);
        $order->setOrderSource($payload->orderSource);
        $order->setLocation($payload->location);
        $now = new \DateTime();
        $order->setOrderDate(null !== $payload->orderDate ? \DateTime::createFromImmutable($payload->orderDate) : $now);
        $order->setDateAdded($now);
        $order->setExchangeRate($this->exchangeRateProvider->getRate($order->getOrderCurrency(), $order->getOrderDate()));
        $order->setLead($lead);
        $order->setEmail($email);
        $order->setCampaignId($this->campaignAttributor->resolveCampaignId($email, $lead, $payload->clickthrough));

        foreach ($payload->items as $itemPayload) {
            $order->addItem($this->createOrderItem($itemPayload, $lead, $payload->orderSource));
        }

        try {
            $this->entityManager->persist($order);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug(
                'Duplicate ecommerce order ignored after concurrent insert.',
                [
                    'order_id'     => $payload->orderId,
                    'order_source' => $payload->orderSource,
                ]
            );

            return null;
        }

        if (null !== $lead) {
            $this->leadCommerceFieldUpdater->syncForLead($lead);
        }

        $this->dispatcher->dispatch(new EcommerceOrderEvent($order), EcommerceEvents::ORDER_RECORDED);

        return $order;
    }

    /**
     * Stores send the same order again when it is refunded or cancelled. Apply that; ignore plain repeats.
     */
    private function handleKnownOrder(OrderPayload $payload): void
    {
        $context = ['order_id' => $payload->orderId, 'order_source' => $payload->orderSource];
        $order   = $this->orderRepository->findOneBy(['orderId' => $payload->orderId, 'orderSource' => $payload->orderSource]);

        if ($order instanceof EcommerceOrder && $this->orderManager->applyRefunds($order, $payload)) {
            $this->logger->notice('Ecommerce order status or refunds updated.', $context + ['status' => $order->getStatus()]);

            return;
        }

        $this->logger->warning('Ecommerce order ignored: an order with this order_id and order_source already exists.', $context);
    }

    private function createOrderItem(OrderItemPayload $itemPayload, ?Lead $lead, string $orderSource): EcommerceOrderItem
    {
        $item = new EcommerceOrderItem();
        $item->setItemId($itemPayload->itemId);
        $item->setItemName($itemPayload->itemName);
        $item->setQty($itemPayload->qty);
        // Lines sent without a category take their product's category from the catalog.
        $item->setCategory($itemPayload->category ?? $this->productRepository->findCategoryForLine(strtolower($orderSource), $itemPayload->productId, $itemPayload->itemId));
        $item->setUnitPrice($itemPayload->unitPrice);
        $item->setRefundedQty($itemPayload->refundedQty);
        $item->setProductId($itemPayload->productId);
        $item->setLead($lead);

        return $item;
    }

    private function resolveEmailFromLead(Lead $lead): ?Email
    {
        $qb = $this->entityManager->getRepository(Stat::class)->createQueryBuilder('s');
        $qb->innerJoin('s.email', 'e')
            ->where('s.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('s.dateSent', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1);

        $stat = $qb->getQuery()->getOneOrNullResult();

        return $stat?->getEmail();
    }
}
