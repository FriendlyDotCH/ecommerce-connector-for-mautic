<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceContactColumnsDictionary;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class EcommerceContactColumnsDictionaryTest extends TestCase
{
    private FieldModel&MockObject $fieldModel;

    private TranslatorInterface&MockObject $translator;

    private CoreParametersHelper&MockObject $coreParametersHelper;

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->fieldModel            = $this->createMock(FieldModel::class);
        $this->translator            = $this->createMock(TranslatorInterface::class);
        $this->coreParametersHelper  = $this->createMock(CoreParametersHelper::class);
        $this->config                = $this->createMock(Config::class);

        $this->fieldModel->method('getFieldList')->willReturn([]);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
    }

    public function testInsertsTotalRevenueAfterPointsWhenPublished(): void
    {
        $this->config->method('isPublished')->willReturn(true);
        $this->coreParametersHelper->method('get')->with('contact_columns', [])->willReturn([
            'name',
            'email',
            'points',
            'last_active',
            'id',
        ]);

        $dictionary = new EcommerceContactColumnsDictionary(
            $this->fieldModel,
            $this->translator,
            $this->coreParametersHelper,
            $this->config
        );

        $columns = $dictionary->getColumns();
        $keys    = array_keys($columns);

        $pointsIndex  = array_search('points', $keys, true);
        $revenueIndex = array_search(LeadCommerceFieldDefinition::LIFETIME_VALUE, $keys, true);

        $this->assertNotFalse($pointsIndex);
        $this->assertNotFalse($revenueIndex);
        $this->assertSame($pointsIndex + 1, $revenueIndex);
    }

    public function testDoesNotAddColumnWhenUnpublished(): void
    {
        $this->config->method('isPublished')->willReturn(false);
        $this->coreParametersHelper->method('get')->with('contact_columns', [])->willReturn([
            'name',
            'points',
            'id',
        ]);

        $dictionary = new EcommerceContactColumnsDictionary(
            $this->fieldModel,
            $this->translator,
            $this->coreParametersHelper,
            $this->config
        );

        $this->assertArrayNotHasKey(
            LeadCommerceFieldDefinition::LIFETIME_VALUE,
            $dictionary->getColumns()
        );
    }
}
