<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\CrossSellCalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\CrossSellSegments;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class CrossSellCalculatorTest extends TestCase
{
    /**
     * 20 customers: 8 bought Candles and Diffusers, 2 only Candles, 4 Soap and Candles,
     * 6 only Soap. Candle buyers are likely Diffuser buyers; Soap says nothing about Diffusers.
     *
     * @return array<int, list<string>>
     */
    private static function customers(): array
    {
        $customers = [];
        $id        = 1;
        foreach ([[8, ['Candles', 'Diffusers']], [2, ['Candles']], [4, ['Soap', 'Candles']], [6, ['Soap']]] as [$count, $categories]) {
            for ($i = 0; $i < $count; ++$i) {
                $customers[$id++] = $categories;
            }
        }

        return $customers;
    }

    public function testRulesNeedSupportAndLift(): void
    {
        $model = CrossSellCalculator::buildModel(self::customers());

        Assert::assertSame(20, $model['customers']);
        // 8 of 14 candle buyers bought diffusers; diffusers overall 8/20: lift 1.43.
        Assert::assertSame(['customers' => 8, 'confidence' => 0.5714, 'lift' => 1.4286], $model['rules']['Candles']['Diffusers']);
        // Every diffuser buyer bought candles: 8/8, lift 20/14.
        Assert::assertSame(1.0, $model['rules']['Diffusers']['Candles']['confidence']);
        // Soap -> Candles: 4 of 10 soap buyers, below the 14/20 average: no rule.
        Assert::assertArrayNotHasKey('Soap', $model['rules']);
        Assert::assertArrayNotHasKey('Soap', $model['rules']['Candles'] ?? []);

        // Too few customers in common: nothing.
        Assert::assertSame([], CrossSellCalculator::buildModel(self::customers(), 9)['rules']);
    }

    public function testOpportunityIsTheBestUnboughtCategory(): void
    {
        $model = CrossSellCalculator::buildModel(self::customers());

        Assert::assertSame(['to' => 'Diffusers', 'from' => 'Candles'], CrossSellCalculator::opportunity(['Candles'], $model));
        Assert::assertSame(['to' => 'Diffusers', 'from' => 'Candles'], CrossSellCalculator::opportunity(['Soap', 'Candles'], $model));
        Assert::assertSame(['to' => 'Candles', 'from' => 'Diffusers'], CrossSellCalculator::opportunity(['Diffusers'], $model));
        Assert::assertNull(CrossSellCalculator::opportunity(['Candles', 'Diffusers'], $model), 'Bought both already.');
        Assert::assertNull(CrossSellCalculator::opportunity(['Soap'], $model));
        Assert::assertNull(CrossSellCalculator::opportunity([], $model));
    }

    public function testSegmentAliasesAreStableAndDistinct(): void
    {
        Assert::assertSame(CrossSellSegments::alias('Düfte & Kerzen'), CrossSellSegments::alias('Düfte & Kerzen'));
        Assert::assertStringStartsWith('ecommerce-cross-sell-dufte-kerzen-', CrossSellSegments::alias('Düfte & Kerzen'));
        Assert::assertNotSame(CrossSellSegments::alias('Gifts!'), CrossSellSegments::alias('Gifts?'));
    }
}
