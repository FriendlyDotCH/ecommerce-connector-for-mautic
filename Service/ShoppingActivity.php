<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;

/**
 * A contact's storefront events condensed for people to read (contact timeline and eCommerce tab).
 *
 * Every cart change arrives as its own event, so a cart that is changed again within the abandonment
 * delay is shown once, as its latest version (the same rule the abandonment detector uses: only the
 * last change of a session can be abandoned). Checkouts are condensed the same way. Each cart or
 * checkout gets a status: abandoned, ordered (an order followed before the next cart session) or open.
 */
class ShoppingActivity
{
    public const KIND_VIEW               = 'view';
    public const KIND_CART               = 'cart';
    public const KIND_CHECKOUT           = 'checkout';
    public const KIND_ABANDONED_CART     = 'abandoned_cart';
    public const KIND_ABANDONED_CHECKOUT = 'abandoned_checkout';

    public const STATUS_OPEN      = 'open';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_ORDERED   = 'ordered';

    private const KIND_BY_TYPE = [
        EcommerceEvent::TYPE_VIEWED_PRODUCT   => self::KIND_VIEW,
        EcommerceEvent::TYPE_ADDED_TO_CART    => self::KIND_CART,
        EcommerceEvent::TYPE_STARTED_CHECKOUT => self::KIND_CHECKOUT,
    ];

    public function __construct(
        private EcommerceEventRepository $eventRepository,
        private EcommerceOrderRepository $orderRepository,
        private Config $config,
    ) {
    }

    /**
     * Newest first.
     *
     * @return list<array{kind: string, event: EcommerceEvent, at: \DateTimeImmutable, status: ?string}>
     */
    public function forLead(int $leadId): array
    {
        $events = $this->eventRepository->findAllForLead($leadId);
        if ([] === $events) {
            return [];
        }

        $orderDates = [];
        foreach ($this->orderRepository->getOrdersForLeadTimeline($leadId)['results'] as $order) {
            $orderDates[] = new \DateTimeImmutable((string) $order['order_date'], new \DateTimeZone('UTC'));
        }

        return $this->build($events, $orderDates, $this->config->getCartAbandonMinutes());
    }

    /**
     * Only the cart, checkout and abandonment entries: product views would flood the timeline.
     *
     * @return list<array{kind: string, event: EcommerceEvent, at: \DateTimeImmutable, status: ?string}>
     */
    public function timelineForLead(int $leadId): array
    {
        return array_values(array_filter(
            $this->forLead($leadId),
            static fn (array $entry): bool => self::KIND_VIEW !== $entry['kind']
        ));
    }

    /**
     * @param list<EcommerceEvent>     $events     oldest first
     * @param list<\DateTimeImmutable> $orderDates
     *
     * @return list<array{kind: string, event: EcommerceEvent, at: \DateTimeImmutable, status: ?string}>
     */
    public function build(array $events, array $orderDates, int $abandonMinutes): array
    {
        $kept = [];
        foreach ($events as $index => $event) {
            $kind = self::KIND_BY_TYPE[$event->getEventType()] ?? null;
            if (null === $kind) {
                continue;
            }

            if (self::KIND_VIEW !== $kind && $this->isSuperseded($event, array_slice($events, $index + 1), $abandonMinutes)) {
                continue;
            }

            $kept[] = ['kind' => $kind, 'event' => $event, 'at' => self::utc($event->getOccurredAt()), 'status' => null];
        }

        // A cart session ends where the next one starts; an order in between means it was bought.
        $cartStarts = array_values(array_map(
            static fn (array $entry): \DateTimeImmutable => $entry['at'],
            array_filter($kept, static fn (array $entry): bool => self::KIND_CART === $entry['kind'])
        ));

        $entries = [];
        foreach ($kept as $entry) {
            if (self::KIND_VIEW !== $entry['kind']) {
                $entry['status'] = $this->status($entry, $cartStarts, $orderDates);
            }
            $entries[] = $entry;

            $abandonedAt = $entry['event']->getAbandonedAt();
            if (null !== $abandonedAt && self::KIND_VIEW !== $entry['kind']) {
                $entries[] = [
                    'kind'   => self::KIND_CART === $entry['kind'] ? self::KIND_ABANDONED_CART : self::KIND_ABANDONED_CHECKOUT,
                    'event'  => $entry['event'],
                    'at'     => self::utc($abandonedAt),
                    'status' => self::STATUS_ABANDONED,
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$b['at'], $b['event']->getId()] <=> [$a['at'], $a['event']->getId()]);

        return $entries;
    }

    /**
     * @param list<EcommerceEvent> $later events after $event, oldest first
     */
    private function isSuperseded(EcommerceEvent $event, array $later, int $abandonMinutes): bool
    {
        if (null !== $event->getAbandonedAt()) {
            return false;
        }

        $limit = self::utc($event->getOccurredAt())->modify(sprintf('+%d minutes', $abandonMinutes));
        foreach ($later as $next) {
            if (self::utc($next->getOccurredAt()) > $limit) {
                return false;
            }
            if ($next->getEventType() === $event->getEventType() && $next->getCartId() === $event->getCartId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{kind: string, event: EcommerceEvent, at: \DateTimeImmutable, status: ?string} $entry
     * @param list<\DateTimeImmutable>                                                             $cartStarts
     * @param list<\DateTimeImmutable>                                                             $orderDates
     */
    private function status(array $entry, array $cartStarts, array $orderDates): string
    {
        if (null !== $entry['event']->getAbandonedAt()) {
            return self::STATUS_ABANDONED;
        }

        $sessionEnd = null;
        foreach ($cartStarts as $start) {
            if ($start > $entry['at']) {
                $sessionEnd = $start;
                break;
            }
        }

        foreach ($orderDates as $orderDate) {
            if ($orderDate >= $entry['at'] && (null === $sessionEnd || $orderDate < $sessionEnd)) {
                return self::STATUS_ORDERED;
            }
        }

        return self::STATUS_OPEN;
    }

    private static function utc(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
    }
}
