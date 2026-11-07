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
    ) {
    }

    public function recordOrder(
        OrderPayload $payload,
        ?Lead $lead,
        ?Email $email = null,
    ): ?EcommerceOrder {
        if ($this->orderRepository->orderExists($payload->orderId, $payload->orderSource)) {
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
        $order->setOrderCurrency($payload->orderCurrency);
        $order->setOrderSource($payload->orderSource);
        $order->setLocation($payload->location);
        $now = new \DateTime();
        $order->setOrderDate(null !== $payload->orderDate ? \DateTime::createFromImmutable($payload->orderDate) : $now);
        $order->setDateAdded($now);
        $order->setLead($lead);
        $order->setEmail($email);

        foreach ($payload->items as $itemPayload) {
            $order->addItem($this->createOrderItem($itemPayload, $lead));
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

    private function createOrderItem(OrderItemPayload $itemPayload, ?Lead $lead): EcommerceOrderItem
    {
        $item = new EcommerceOrderItem();
        $item->setItemId($itemPayload->itemId);
        $item->setItemName($itemPayload->itemName);
        $item->setQty($itemPayload->qty);
        $item->setCategory($itemPayload->category);
        $item->setUnitPrice($itemPayload->unitPrice);
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
