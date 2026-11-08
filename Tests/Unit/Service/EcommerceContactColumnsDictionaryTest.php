<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceContactColumnsDictionary;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class EcommerceContactColumnsDictionaryTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->config     = $this->createMock(Config::class);

        $this->translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
    }

    public function testInsertsTotalRevenueAfterPointsWhenPublished(): void
    {
        $this->config->method('isPublished')->willReturn(true);

        $columns = (new EcommerceContactColumnsDictionary($this->translator, $this->config))->addRevenueColumn([
            'name'        => 'Name',
            'email'       => 'Email',
            'points'      => 'Points',
            'last_active' => 'Last active',
            'id'          => 'ID',
        ]);
        $keys = array_keys($columns);

        $pointsIndex  = array_search('points', $keys, true);
        $revenueIndex = array_search(LeadCommerceFieldDefinition::LIFETIME_VALUE, $keys, true);

        $this->assertNotFalse($pointsIndex);
        $this->assertNotFalse($revenueIndex);
        $this->assertSame($pointsIndex + 1, $revenueIndex);
    }

    public function testInsertsBeforeIdWhenPointsColumnMissing(): void
    {
        $this->config->method('isPublished')->willReturn(true);

        $columns = (new EcommerceContactColumnsDictionary($this->translator, $this->config))->addRevenueColumn([
            'name' => 'Name',
            'id'   => 'ID',
        ]);

        $this->assertSame(['name', LeadCommerceFieldDefinition::LIFETIME_VALUE, 'id'], array_keys($columns));
    }

    public function testDoesNotAddColumnWhenUnpublished(): void
    {
        $this->config->method('isPublished')->willReturn(false);

        $columns = (new EcommerceContactColumnsDictionary($this->translator, $this->config))->addRevenueColumn([
            'name'   => 'Name',
            'points' => 'Points',
            'id'     => 'ID',
        ]);

        $this->assertArrayNotHasKey(LeadCommerceFieldDefinition::LIFETIME_VALUE, $columns);
    }
}
