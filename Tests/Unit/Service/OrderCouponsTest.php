<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponIssuer;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\ShopifyOrderMapper;
use MauticPlugin\EcommerceConnectorBundle\Service\WooCommerceOrderMapper;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class OrderCouponsTest extends TestCase
{
    public function testParsesListsStringsAndObjects(): void
    {
        Assert::assertSame(['SAVE-1', 'b'], OrderPayloadParser::parseCoupons(['SAVE-1', ' b ', 'save-1', '', ['x' => 1]]));
        Assert::assertSame(['a', 'b'], OrderPayloadParser::parseCoupons('a, b'));
        Assert::assertSame(['c'], OrderPayloadParser::parseCoupons([['code' => 'c']]));
        Assert::assertSame([], OrderPayloadParser::parseCoupons(null));
        Assert::assertCount(20, OrderPayloadParser::parseCoupons(array_map('strval', range(1, 30))));
        Assert::assertSame([], OrderPayloadParser::parseCoupons([str_repeat('x', 65)]));
    }

    public function testNativeWebhooksPassTheirCodes(): void
    {
        $woo = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/woocommerce-order.json'), true);
        $woo['coupon_lines'] = [['id' => 5, 'code' => 'back-abcdefgh', 'discount' => '5.00']];
        Assert::assertSame(['back-abcdefgh'], WooCommerceOrderMapper::map($woo)['coupons']);

        $shopify = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/shopify-order.json'), true);
        $shopify['discount_codes'] = [['code' => 'BACK-ABCDEFGH', 'amount' => '5.00', 'type' => 'percentage']];
        Assert::assertSame(['BACK-ABCDEFGH'], ShopifyOrderMapper::map($shopify)['coupons']);
    }

    public function testGeneratedCodes(): void
    {
        Assert::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', CouponIssuer::generateCode());
        Assert::assertMatchesRegularExpression('/^WINBACK-[A-HJKMNP-Z2-9]{8}$/', CouponIssuer::generateCode('win-back!'));
        Assert::assertNotSame(CouponIssuer::generateCode(), CouponIssuer::generateCode());
    }
}
