<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\OpportunityFinder;
use MauticPlugin\EcommerceConnectorBundle\Service\OpportunitySegments;
use PHPUnit\Framework\TestCase;

class OpportunityFinderTest extends TestCase
{
    public function testSecondOrderWindowNeedsEnoughRepeatCustomers(): void
    {
        $this->assertNull(OpportunityFinder::secondOrderWindow([10, 20, 30, 40]));
        $this->assertNull(OpportunityFinder::secondOrderWindow([]));
    }

    public function testSecondOrderWindowIsTheMiddleHalf(): void
    {
        // 25th percentile 20, 75th 40, whatever the input order.
        $this->assertSame([20, 40], OpportunityFinder::secondOrderWindow([50, 10, 30, 20, 40]));
        $this->assertSame([30, 30], OpportunityFinder::secondOrderWindow([30, 30, 30, 30, 30, 50]));
        // Interpolated bounds round outwards.
        $this->assertSame([12, 33], OpportunityFinder::secondOrderWindow([5, 15, 25, 35, 45, 12]));
    }

    public function testSecondOrderWindowStartsAtOneDay(): void
    {
        $this->assertSame([1, 1], OpportunityFinder::secondOrderWindow([0, 0, 0, 0, 0]));
    }

    public function testBigSpenderCutoffMatchesRfmQuintiles(): void
    {
        $values = [100.0, 200.0, 300.0, 400.0, 500.0, 600.0, 700.0, 800.0, 900.0, 1000.0];

        // Same cutoff the RFM monetary score uses for 4 and up.
        $this->assertSame(\MauticPlugin\EcommerceConnectorBundle\Service\LifecycleCalculator::quintileCutoffs($values)[2], OpportunityFinder::percentileCutoff($values, 60));
        $this->assertSame(700.0, OpportunityFinder::percentileCutoff(array_reverse($values), 60));
        $this->assertNull(OpportunityFinder::percentileCutoff([], 60));
    }

    public function testSegmentAliasIsStableAndDistinct(): void
    {
        $this->assertSame(OpportunitySegments::alias('cross_sell:Rózsa'), OpportunitySegments::alias('cross_sell:Rózsa'));
        $this->assertNotSame(OpportunitySegments::alias('cross_sell:Rózsa'), OpportunitySegments::alias('cross_sell:Rozsa'));
        $this->assertStringStartsWith('ecommerce-opportunity-cross-sell-rozsa-', OpportunitySegments::alias('cross_sell:Rózsa'));
        $this->assertLessThanOrEqual(191, strlen(OpportunitySegments::alias(str_repeat('x', 500))));
    }
}
