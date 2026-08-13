<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\LocationFormatter;
use PHPUnit\Framework\TestCase;

class LocationFormatterTest extends TestCase
{
    public function testFormatsHyphenatedSlug(): void
    {
        $formatter = new LocationFormatter();

        $this->assertSame('The dragon', $formatter->format('the-dragon'));
        $this->assertSame('Hixson', $formatter->format('hixson'));
        $this->assertNull($formatter->format(null));
        $this->assertNull($formatter->format('   '));
    }
}
