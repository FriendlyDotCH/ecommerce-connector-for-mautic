<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventMatcher;
use PHPUnit\Framework\TestCase;

class StorefrontEventMatcherTest extends TestCase
{
    public function testCartFilters(): void
    {
        $cart = new EcommerceEvent();
        $cart->setEventType(EcommerceEvent::TYPE_ADDED_TO_CART);
        $cart->setCartValue('45.0000');
        $cart->setCartItems([
            ['item_id' => 'CND-1', 'item_name' => 'Lavender Candle', 'category' => 'Candles'],
            ['item_id' => 'SOAP-1', 'item_name' => 'Rose Soap', 'category' => 'Bath'],
        ]);

        $this->assertTrue(StorefrontEventMatcher::matches($cart, []));
        $this->assertTrue(StorefrontEventMatcher::matches($cart, ['min_value' => '40']));
        $this->assertFalse(StorefrontEventMatcher::matches($cart, ['min_value' => '50']));
        $this->assertTrue(StorefrontEventMatcher::matches($cart, ['categories' => 'bath']));
        $this->assertFalse(StorefrontEventMatcher::matches($cart, ['categories' => 'Diffusers']));
        $this->assertTrue(StorefrontEventMatcher::matches($cart, ['products' => 'lavender candle']));
        $this->assertTrue(StorefrontEventMatcher::matches($cart, ['categories' => 'Candles', 'products' => 'SOAP-1']));
    }

    public function testViewedProductUsesProductAndPrice(): void
    {
        $view = new EcommerceEvent();
        $view->setEventType(EcommerceEvent::TYPE_VIEWED_PRODUCT);
        $view->setProductId('DIF-9');
        $view->setProductName('Diffuser');
        $view->setCategory('Diffusers');
        $view->setPrice('30.0000');

        $this->assertTrue(StorefrontEventMatcher::matches($view, ['categories' => 'Diffusers', 'min_value' => '30']));
        $this->assertFalse(StorefrontEventMatcher::matches($view, ['min_value' => '30.01']));
        $this->assertFalse(StorefrontEventMatcher::matches($view, ['products' => 'CND-1']));
    }
}
