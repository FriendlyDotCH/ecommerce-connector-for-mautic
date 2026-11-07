<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CampaignSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Psr\Log\LoggerInterface;

/**
 * Finds abandoned carts, checkouts and browsing and fires the abandonment decisions, once per
 * event. An event is abandoned when it is the contact's latest relevant activity, older than
 * the configured delay, and no counted order followed it:
 *
 * - checkout: a started checkout with no later cart or checkout event
 * - cart: an added to cart with no later cart or checkout event
 * - browsing: a viewed product with no later storefront event of any kind
 */
class AbandonmentDetector
{
    /**
     * Events older than delay + this are left alone, so a first run or an outage does not
     * send emails about week-old carts.
     */
    public const LOOKBACK_HOURS = 48;

    public const BATCH_SIZE = 200;

    private const LATER_ACTIVITY = [
        EcommerceEvent::TYPE_STARTED_CHECKOUT => [EcommerceEvent::TYPE_ADDED_TO_CART, EcommerceEvent::TYPE_STARTED_CHECKOUT],
        EcommerceEvent::TYPE_ADDED_TO_CART    => [EcommerceEvent::TYPE_ADDED_TO_CART, EcommerceEvent::TYPE_STARTED_CHECKOUT],
        EcommerceEvent::TYPE_VIEWED_PRODUCT   => EcommerceEvent::TYPES,
    ];

    public function __construct(
        private Connection $connection,
        private EcommerceEventRepository $eventRepository,
        private CampaignSubscriber $campaignSubscriber,
        private Config $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> event type => number of abandonments fired
     */
    public function run(?\DateTimeInterface $now = null): array
    {
        $now    = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $result = [];

        foreach (self::LATER_ACTIVITY as $type => $laterTypes) {
            $delay = EcommerceEvent::TYPE_VIEWED_PRODUCT === $type ? $this->config->getBrowseAbandonMinutes() : $this->config->getCartAbandonMinutes();
            $until = $now->modify(sprintf('-%d minutes', $delay));
            $from  = $until->modify(sprintf('-%d hours', self::LOOKBACK_HOURS));

            $result[$type] = 0;
            foreach ($this->findCandidates($type, $laterTypes, $from, $until) as $eventId) {
                if ($this->fire($eventId, $now)) {
                    ++$result[$type];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<string> $laterTypes
     *
     * @return list<int>
     */
    private function findCandidates(string $type, array $laterTypes, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT e.id FROM {$prefix}ecommerce_events e
             WHERE e.event_type = ? AND e.abandoned_at IS NULL AND e.occurred_at BETWEEN ? AND ?
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}ecommerce_events later
                   WHERE later.lead_id = e.lead_id AND later.event_type IN (?)
                     AND (later.occurred_at > e.occurred_at OR (later.occurred_at = e.occurred_at AND later.id > e.id))
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}ecommerce_orders o
                   WHERE o.lead_id = e.lead_id AND o.status IN (?) AND o.order_date >= e.occurred_at
               )
             ORDER BY e.occurred_at ASC
             LIMIT ".self::BATCH_SIZE,
            [$type, $from->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'), $laterTypes, EcommerceOrder::COUNTED_STATUSES],
            [null, null, null, ArrayParameterType::STRING, ArrayParameterType::STRING]
        ));
    }

    private function fire(int $eventId, \DateTimeImmutable $now): bool
    {
        // Claim the event first, so overlapping runs cannot fire it twice.
        $claimed = $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_events SET abandoned_at = ? WHERE id = ? AND abandoned_at IS NULL',
            [$now->format('Y-m-d H:i:s'), $eventId]
        );

        $event = 1 === $claimed ? $this->eventRepository->find($eventId) : null;
        if (!$event instanceof EcommerceEvent) {
            return false;
        }

        try {
            $this->campaignSubscriber->triggerAbandonment($event);
        } catch (\Throwable $exception) {
            $this->logger->error('Ecommerce abandonment could not be processed: '.$exception->getMessage(), ['event_id' => $eventId]);

            return false;
        }

        return true;
    }
}
