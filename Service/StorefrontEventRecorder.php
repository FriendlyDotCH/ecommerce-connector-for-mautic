<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\StorefrontEventPayload;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Event\StorefrontEventRecordedEvent;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Stores storefront events for identified contacts only, and only when switched on.
 */
class StorefrontEventRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Config $config,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function record(StorefrontEventPayload $payload, ?Lead $lead): ?EcommerceEvent
    {
        if (!$this->config->isStorefrontEventsEnabled()) {
            $this->logger->notice('Ecommerce storefront event ignored: storefront events are switched off.');

            return null;
        }

        if (null === $lead || !$lead->getId() || $lead->isAnonymous()) {
            $this->logger->notice('Ecommerce storefront event ignored: no identified contact.', ['event' => $payload->eventType]);

            return null;
        }

        $event = new EcommerceEvent();
        $event->setLead($lead);
        $event->setEventType($payload->eventType);
        $event->setProductId($payload->productId);
        $event->setProductName($payload->productName);
        $event->setCategory($payload->category);
        $event->setPrice($payload->price);
        $event->setCurrency($payload->currency);
        $event->setProductUrl($payload->productUrl);
        $event->setImageUrl($payload->imageUrl);
        $event->setCartId($payload->cartId);
        $event->setCartValue($payload->cartValue);
        $event->setCartUrl($payload->cartUrl);
        $event->setCartItems($payload->cartItems);
        $event->setSource($payload->source);
        $event->setOccurredAt(\DateTime::createFromImmutable($payload->occurredAt));
        $event->setDateAdded(new \DateTime());

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new StorefrontEventRecordedEvent($event), EcommerceEvents::STOREFRONT_EVENT_RECORDED);

        return $event;
    }
}
