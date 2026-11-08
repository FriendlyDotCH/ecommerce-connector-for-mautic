<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\ShoppingActivity;
use PHPUnit\Framework\TestCase;

class ShoppingActivityTest extends TestCase
{
    private ShoppingActivity $activity;

    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->activity = new ShoppingActivity(
            $this->createMock(EcommerceEventRepository::class),
            $this->createMock(EcommerceOrderRepository::class),
            $this->createMock(Config::class)
        );
    }

    public function testCartChangesWithinTheDelayAreShownOnceAsTheLatestCart(): void
    {
        $entries = $this->activity->build([
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:00', 'c1', '10'),
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:05', 'c1', '20'),
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:30', 'c1', '30'),
        ], [], 60);

        $this->assertSame([[ShoppingActivity::KIND_CART, '30', ShoppingActivity::STATUS_OPEN]], $this->summary($entries));
    }

    public function testChangesAfterTheDelayStartANewCart(): void
    {
        $entries = $this->activity->build([
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:00', 'c1', '10'),
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '12:00', 'c1', '20'),
        ], [], 60);

        $this->assertSame([
            [ShoppingActivity::KIND_CART, '20', ShoppingActivity::STATUS_OPEN],
            [ShoppingActivity::KIND_CART, '10', ShoppingActivity::STATUS_OPEN],
        ], $this->summary($entries));
    }

    public function testAnAbandonedCartGetsItsOwnEntryAndIsNeverMerged(): void
    {
        $abandoned = $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:00', 'c1', '10');
        $abandoned->setAbandonedAt(new \DateTimeImmutable('2026-11-08 10:15:00', new \DateTimeZone('UTC')));

        $entries = $this->activity->build([
            $abandoned,
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:20', 'c1', '25'),
        ], [], 60);

        $this->assertSame([
            [ShoppingActivity::KIND_CART, '25', ShoppingActivity::STATUS_OPEN],
            [ShoppingActivity::KIND_ABANDONED_CART, '10', ShoppingActivity::STATUS_ABANDONED],
            [ShoppingActivity::KIND_CART, '10', ShoppingActivity::STATUS_ABANDONED],
        ], $this->summary($entries));
        $this->assertSame('2026-11-08 10:15:00', $entries[1]['at']->format('Y-m-d H:i:s'));
    }

    public function testAnOrderBeforeTheNextCartMarksCartAndCheckoutOrdered(): void
    {
        $entries = $this->activity->build([
            $this->event(EcommerceEvent::TYPE_VIEWED_PRODUCT, '09:55', null, null),
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '10:00', 'c1', '10'),
            $this->event(EcommerceEvent::TYPE_STARTED_CHECKOUT, '10:02', 'c1', '10'),
            $this->event(EcommerceEvent::TYPE_ADDED_TO_CART, '15:00', 'c1', '40'),
        ], [new \DateTimeImmutable('2026-11-08 10:04:00', new \DateTimeZone('UTC'))], 60);

        $this->assertSame([
            [ShoppingActivity::KIND_CART, '40', ShoppingActivity::STATUS_OPEN],
            [ShoppingActivity::KIND_CHECKOUT, '10', ShoppingActivity::STATUS_ORDERED],
            [ShoppingActivity::KIND_CART, '10', ShoppingActivity::STATUS_ORDERED],
            [ShoppingActivity::KIND_VIEW, null, null],
        ], $this->summary($entries));
    }

    private function event(string $type, string $time, ?string $cartId, ?string $value): EcommerceEvent
    {
        $event = new EcommerceEvent();
        $event->setEventType($type);
        $event->setCartId($cartId);
        $event->setCartValue($value);
        $event->setOccurredAt(new \DateTimeImmutable('2026-11-08 '.$time.':00', new \DateTimeZone('UTC')));
        (new \ReflectionProperty(EcommerceEvent::class, 'id'))->setValue($event, $this->nextId++);

        return $event;
    }

    /**
     * @param list<array{kind: string, event: EcommerceEvent, at: \DateTimeImmutable, status: ?string}> $entries
     *
     * @return list<array{0: string, 1: ?string, 2: ?string}>
     */
    private function summary(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => [$entry['kind'], $entry['event']->getCartValue(), $entry['status']],
            $entries
        );
    }
}
