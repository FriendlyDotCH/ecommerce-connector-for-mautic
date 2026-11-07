<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\DTO;

use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use PHPUnit\Framework\TestCase;

class PurchaseCriteriaTest extends TestCase
{
    public function testScopeWithoutValuesFallsBackToAny(): void
    {
        $criteria = new PurchaseCriteria(PurchaseCriteria::METRIC_SPEND, PurchaseCriteria::SCOPE_CATEGORY, [' ', '']);

        $this->assertSame(PurchaseCriteria::SCOPE_ANY, $criteria->scope);
        $this->assertSame([], $criteria->values);
        $this->assertFalse($criteria->needsItems());
    }

    public function testValuesAreTrimmedAndDeduplicated(): void
    {
        $criteria = new PurchaseCriteria(PurchaseCriteria::METRIC_ORDERS, PurchaseCriteria::SCOPE_PRODUCT, PurchaseCriteria::splitList(' a, b ,a,,'));

        $this->assertSame(['a', 'b'], $criteria->values);
        $this->assertTrue($criteria->needsItems());
    }

    public function testQuantityAlwaysNeedsItems(): void
    {
        $this->assertTrue((new PurchaseCriteria(PurchaseCriteria::METRIC_QTY))->needsItems());
    }

    public function testRejectsUnknownMetricScopeAndNegativeDays(): void
    {
        foreach ([['revenue', 'any', 0], ['spend', 'brand', 0], ['spend', 'any', -1]] as [$metric, $scope, $days]) {
            try {
                new PurchaseCriteria($metric, $scope, [], $days);
                $this->fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
