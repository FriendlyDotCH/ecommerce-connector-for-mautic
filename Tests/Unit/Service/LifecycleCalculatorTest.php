<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LifecycleCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LifecycleCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int, 2: ?float, 3: float, 4: ?float, 5: string}>
     */
    public static function stageProvider(): iterable
    {
        // orders, days since, average gap, monetary, VIP cutoff => stage
        yield 'one recent order'               => [1, 10, null, 50, null, 'new'];
        yield 'one old order'                  => [1, 91, null, 50, null, 'at_risk'];
        yield 'regular buyer on time'          => [3, 40, 30.0, 90, null, 'active'];
        yield 'regular buyer overdue'          => [3, 46, 30.0, 90, null, 'at_risk'];
        yield 'frequent buyer, minimum gap'    => [6, 20, 5.0, 300, null, 'vip'];
        yield 'frequent buyer, past minimum'   => [6, 31, 5.0, 300, null, 'at_risk'];
        yield 'top spender'                    => [2, 10, 60.0, 900, 800.0, 'vip'];
        yield 'five orders'                    => [5, 10, 20.0, 100, 800.0, 'vip'];
        yield 'lapsed beats everything'        => [9, 400, 20.0, 9000, 800.0, 'lapsed'];
        yield 'at risk beats VIP'              => [9, 100, 20.0, 9000, 800.0, 'at_risk'];
    }

    #[DataProvider('stageProvider')]
    public function testStage(int $orders, int $daysSince, ?float $avgGap, float $monetary, ?float $vipCutoff, string $expected): void
    {
        $settings = array_map('floatval', Config::LIFECYCLE_DEFAULTS);

        $this->assertSame($expected, LifecycleCalculator::stage(
            ['orders' => $orders, 'days_since' => $daysSince, 'avg_gap' => $avgGap, 'monetary' => $monetary],
            $settings,
            $vipCutoff
        ));
    }

    public function testQuintileCutoffsAndScores(): void
    {
        $cutoffs = LifecycleCalculator::quintileCutoffs([10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
        $this->assertSame([30.0, 50.0, 70.0, 90.0], $cutoffs);

        $this->assertSame(1, LifecycleCalculator::quintileScore(10, $cutoffs));
        $this->assertSame(3, LifecycleCalculator::quintileScore(55, $cutoffs));
        $this->assertSame(5, LifecycleCalculator::quintileScore(100, $cutoffs));

        // Recency: fewer days is better.
        $this->assertSame(5, LifecycleCalculator::quintileScore(5, $cutoffs, true));
        $this->assertSame(1, LifecycleCalculator::quintileScore(95, $cutoffs, true));
    }

    public function testTiesDoNotLiftEveryoneToTheTop(): void
    {
        // Most customers ordered once.
        $cutoffs = LifecycleCalculator::quintileCutoffs([1, 1, 1, 1, 1, 1, 1, 2, 3, 8]);

        $this->assertSame(1, LifecycleCalculator::quintileScore(1, $cutoffs));
        $this->assertSame(5, LifecycleCalculator::quintileScore(8, $cutoffs));
        $this->assertSame([], LifecycleCalculator::quintileCutoffs([]));
    }
}
