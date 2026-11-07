<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use PHPUnit\Framework\TestCase;

class CurrencyFormatterTest extends TestCase
{
    public function testFormatsUsdWithSymbol(): void
    {
        $formatter = new CurrencyFormatter();

        $this->assertSame('$15.25', $formatter->format('USD', '15.25', 'en_US'));
    }

    public function testFormatsEurWithSymbol(): void
    {
        $formatter = new CurrencyFormatter();

        $this->assertSame('€199.00', $formatter->format('EUR', '199', 'en_US'));
    }

    public function testWholeNumberCurrenciesHaveNoDecimals(): void
    {
        $formatter = new CurrencyFormatter();

        $this->assertSame("4\u{a0}990\u{a0}Ft", $formatter->format('HUF', '4990', 'hu_HU'));
        $this->assertSame("19\u{a0}960\u{a0}Ft", $formatter->format('huf', 19960.0, 'hu_HU'));
        $this->assertSame('¥1,200', $formatter->format('JPY', '1200', 'en_US'));
    }

    public function testMoneyLocaleSettingIsUsedWithoutAnExplicitLocale(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMoneyLocale')->willReturn('hu_HU');

        $this->assertSame("4\u{a0}990\u{a0}Ft", (new CurrencyFormatter($config))->format('HUF', '4990'));
        $this->assertSame('€199.00', (new CurrencyFormatter($config))->format('EUR', '199', 'en_US'), 'An explicit locale still wins.');
    }
}
