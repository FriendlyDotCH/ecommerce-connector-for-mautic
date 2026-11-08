<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductChangeDetector;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ProductChangeDetectorTest extends TestCase
{
    public function testNewProductsChangeNothing(): void
    {
        Assert::assertSame([], ProductChangeDetector::detect(null, ['stock_status' => 'instock', 'price' => '10']));
    }

    public function testBackInStock(): void
    {
        $stored = self::product(['stockStatus' => 'outofstock', 'stockQuantity' => 0, 'price' => '20.0000']);

        Assert::assertSame(
            [['type' => ProductChangeDetector::BACK_IN_STOCK, 'old' => '0', 'new' => '12']],
            ProductChangeDetector::detect($stored, ['stock_status' => 'instock', 'stock_quantity' => 12, 'price' => '20.0000'])
        );
        // Untracked stock: in stock without a quantity.
        Assert::assertSame(ProductChangeDetector::BACK_IN_STOCK, ProductChangeDetector::detect($stored, ['stock_status' => 'instock', 'price' => '20'])[0]['type']);
        // Backorders are not "back in stock".
        Assert::assertSame([], ProductChangeDetector::detect($stored, ['stock_status' => 'onbackorder', 'price' => '20']));
    }

    public function testPriceDropAndStockDrop(): void
    {
        $stored = self::product(['stockStatus' => 'instock', 'stockQuantity' => 10, 'price' => '20.0000']);

        Assert::assertSame([
            ['type' => ProductChangeDetector::PRICE_DROP, 'old' => '20.0000', 'new' => '15.0000'],
            ['type' => ProductChangeDetector::STOCK_DROP, 'old' => '10', 'new' => '3'],
        ], ProductChangeDetector::detect($stored, ['stock_status' => 'instock', 'stock_quantity' => 3, 'price' => '15.0000']));

        // A price rise, stock going up, selling out, or a free product: no alert.
        Assert::assertSame([], ProductChangeDetector::detect($stored, ['stock_status' => 'instock', 'stock_quantity' => 11, 'price' => '25']));
        Assert::assertSame([], ProductChangeDetector::detect($stored, ['stock_status' => 'outofstock', 'stock_quantity' => 0, 'price' => '0']));
    }

    public function testHiddenAndOlderUpdatesChangeNothing(): void
    {
        $stored = self::product(['stockStatus' => 'outofstock', 'stockQuantity' => 0, 'price' => '20.0000', 'dateModified' => new \DateTimeImmutable('2026-11-08 10:00:00', new \DateTimeZone('UTC'))]);

        Assert::assertSame([], ProductChangeDetector::detect($stored, ['status' => 'draft', 'stock_status' => 'instock', 'price' => '10']));
        Assert::assertSame([], ProductChangeDetector::detect($stored, ['date_modified' => '2026-11-08 09:59:00', 'stock_status' => 'instock', 'price' => '10']));
        Assert::assertCount(2, ProductChangeDetector::detect($stored, ['date_modified' => '2026-11-08 10:01:00', 'stock_status' => 'instock', 'price' => '10']));
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function product(array $properties): EcommerceProduct
    {
        $product    = (new \ReflectionClass(EcommerceProduct::class))->newInstanceWithoutConstructor();
        $properties += ['store' => 'woocommerce', 'externalId' => '155', 'name' => 'Lavender Candle'];
        foreach ($properties as $name => $value) {
            (new \ReflectionProperty(EcommerceProduct::class, $name))->setValue($product, $value);
        }

        return $product;
    }
}
