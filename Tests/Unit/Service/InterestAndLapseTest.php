<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\CategoryLapseCalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\InterestCalculator;
use PHPUnit\Framework\TestCase;

class InterestAndLapseTest extends TestCase
{
    public function testOneViewIsNotInterestButTwoDaysOrACartAre(): void
    {
        $this->assertNull(InterestCalculator::pick([$this->view('2026-11-01', 'Rose soap')], []));
        // Two views on the same day count once.
        $this->assertNull(InterestCalculator::pick([$this->view('2026-11-01', 'Rose soap'), $this->view('2026-11-01', 'Rose soap')], []));

        $this->assertSame(
            ['product' => 'Rose soap', 'category' => 'Soap', 'score' => 2],
            InterestCalculator::pick([$this->view('2026-11-01', 'Rose soap'), $this->view('2026-11-03', 'Rose soap')], [])
        );
        $this->assertSame('Gift set', InterestCalculator::pick([$this->cart('2026-11-02', 'c1', 'Gift set')], [])['product'] ?? null);
    }

    public function testCartUpdatesInOneCartCountOnce(): void
    {
        $signals = [$this->cart('2026-11-02', 'c1', 'Gift set'), $this->cart('2026-11-02', 'c1', 'Gift set'), $this->cart('2026-11-04', 'c2', 'Gift set')];
        $this->assertSame(4, InterestCalculator::pick($signals, [])['score'] ?? null);
    }

    public function testBoughtProductsAreSkippedByIdSkuOrName(): void
    {
        $signals = [$this->cart('2026-11-02', 'c1', 'Gift set', 'GIFT-1'), $this->view('2026-11-01', 'Rose soap', '35'), $this->view('2026-11-02', 'Rose soap', '35')];

        $this->assertSame('Rose soap', InterestCalculator::pick($signals, ['gift-1' => true])['product'] ?? null);
        $this->assertSame('Gift set', InterestCalculator::pick($signals, ['35' => true])['product'] ?? null);
        $this->assertNull(InterestCalculator::pick($signals, ['gift set' => true, 'rose soap' => true]));
    }

    public function testStrongestThenMostRecentWins(): void
    {
        $signals = [
            $this->view('2026-11-01', 'Rose soap'), $this->view('2026-11-02', 'Rose soap'),
            $this->view('2026-11-03', 'Bath bomb'), $this->view('2026-11-04', 'Bath bomb'),
        ];
        $this->assertSame('Bath bomb', InterestCalculator::pick($signals, [])['product'] ?? null, 'Equal points: the more recent.');

        $signals[] = $this->cart('2026-10-20', 'c1', 'Rose soap');
        $this->assertSame('Rose soap', InterestCalculator::pick($signals, [])['product'] ?? null, 'More points.');
    }

    public function testCategoryLapsesAfterTwiceTheUsualGap(): void
    {
        $now = new \DateTimeImmutable('2026-11-09');

        // Every 50 days, last 150 days ago: lapsed (150 > 100).
        $this->assertSame(['category' => 'Soap', 'days' => 150], CategoryLapseCalculator::lapsed(['Soap' => ['2026-04-13', '2026-06-12']], $now));
        // Every 30 days, last 59 days ago: the 60-day minimum applies.
        $this->assertNull(CategoryLapseCalculator::lapsed(['Soap' => ['2026-08-12', '2026-09-11']], $now));
        // Bought once: no rhythm to break.
        $this->assertNull(CategoryLapseCalculator::lapsed(['Soap' => ['2026-01-01']], $now));
        // The same day twice is one purchase.
        $this->assertNull(CategoryLapseCalculator::lapsed(['Soap' => ['2026-01-01', '2026-01-01']], $now));
    }

    public function testMostBoughtLapsedCategoryWins(): void
    {
        $now = new \DateTimeImmutable('2026-11-09');
        $lapse = CategoryLapseCalculator::lapsed([
            'Soap'    => ['2026-03-01', '2026-04-01'],
            'Candles' => ['2026-02-01', '2026-03-01', '2026-04-01'],
            'Gifts'   => ['2026-10-01', '2026-11-01'],
        ], $now);

        $this->assertSame('Candles', $lapse['category'] ?? null);
    }

    /**
     * @return array{type: string, day: string, cart: ?string, ids: list<string>, name: string, category: ?string}
     */
    private function view(string $day, string $name, string $id = ''): array
    {
        return ['type' => 'viewed_product', 'day' => $day, 'cart' => null, 'ids' => array_values(array_filter([$id])), 'name' => $name, 'category' => 'Soap'];
    }

    /**
     * @return array{type: string, day: string, cart: ?string, ids: list<string>, name: string, category: ?string}
     */
    private function cart(string $day, string $cart, string $name, string $id = ''): array
    {
        return ['type' => 'added_to_cart', 'day' => $day, 'cart' => $cart, 'ids' => array_values(array_filter([$id])), 'name' => $name, 'category' => 'Gifts'];
    }
}
