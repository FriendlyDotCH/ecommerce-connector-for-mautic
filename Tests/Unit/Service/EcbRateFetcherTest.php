<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Service\EcbRateFetcher;
use PHPUnit\Framework\TestCase;

class EcbRateFetcherTest extends TestCase
{
    public function testParsesTheEcbFeed(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <gesmes:subject>Reference rates</gesmes:subject>
    <Cube>
        <Cube time="2026-11-06">
            <Cube currency="USD" rate="1.0812"/>
            <Cube currency="HUF" rate="395.12"/>
        </Cube>
        <Cube time="2026-11-05">
            <Cube currency="HUF" rate="394.80"/>
            <Cube currency="bad" rate="-1"/>
        </Cube>
    </Cube>
</gesmes:Envelope>
XML;

        $this->assertSame([
            ['date' => '2026-11-06', 'currency' => 'USD', 'per_eur' => 1.0812],
            ['date' => '2026-11-06', 'currency' => 'HUF', 'per_eur' => 395.12],
            ['date' => '2026-11-05', 'currency' => 'HUF', 'per_eur' => 394.8],
        ], EcbRateFetcher::parse($xml));
    }

    public function testRejectsInvalidXml(): void
    {
        $this->expectException(\RuntimeException::class);
        EcbRateFetcher::parse('<not xml');
    }
}
