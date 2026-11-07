<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Platform;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Platform\StorePlatformInterface;
use MauticPlugin\EcommerceConnectorBundle\Platform\WooCommercePlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class WooCommercePlatformTest extends TestCase
{
    private WooCommercePlatform $platform;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getDefaultCurrency')->willReturn('HUF');

        $this->platform = new WooCommercePlatform($config);
    }

    public function testMapsAVariableProduct(): void
    {
        $product = $this->platform->mapProduct(self::fixture('woocommerce-product-variable.json'));

        $this->assertSame('160', $product['external_id']);
        $this->assertSame('DIF-REED', $product['sku']);
        $this->assertSame('Diffusers', $product['category'], 'The default category is skipped.');
        $this->assertSame(['Uncategorized', 'Diffusers', 'Gifts'], $product['categories']);
        $this->assertSame(['164', '165', '166'], $product['variation_ids']);
        $this->assertSame('12990.0000', $product['price']);
        $this->assertNull($product['regular_price']);
        $this->assertSame('HUF', $product['currency']);
        $this->assertSame('instock', $product['stock_status']);
        $this->assertSame('2026-11-02 09:14:51', $product['date_modified']);
        $this->assertSame('https://shop.example/wp-content/uploads/diffuser.jpg', $product['image_url']);
    }

    public function testRecognisesWebhookResources(): void
    {
        $topic = static fn (string $topic): Request => new Request([], [], [], [], [], ['HTTP_X_WC_WEBHOOK_TOPIC' => $topic]);

        $this->assertSame(StorePlatformInterface::RESOURCE_PING, $this->platform->getResource($topic(''), null));
        $this->assertSame(StorePlatformInterface::RESOURCE_ORDER, $this->platform->getResource($topic('order.updated'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_PRODUCT, $this->platform->getResource($topic('product.updated'), ['id' => 1]));
        $this->assertSame(StorePlatformInterface::RESOURCE_PRODUCT_DELETE, $this->platform->getResource($topic('product.deleted'), ['id' => 1]));
        $this->assertNull($this->platform->getResource($topic('customer.created'), ['id' => 1]));
    }

    public function testSignature(): void
    {
        $body    = '{"id":1}';
        $request = new Request([], [], [], [], [], ['HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 's3cret', true))], $body);

        $this->assertTrue($this->platform->isSignatureValid($request, 's3cret'));
        $this->assertFalse($this->platform->isSignatureValid($request, 'other'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
