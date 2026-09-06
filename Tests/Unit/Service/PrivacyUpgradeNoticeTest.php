<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Model\PluginModel;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrivacyUpgradeNoticeTest extends TestCase
{
    private IntegrationHelper&MockObject $integrationHelper;

    private PluginModel&MockObject $pluginModel;

    private PrivacyUpgradeNotice $notice;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->pluginModel        = $this->createMock(PluginModel::class);
        $this->notice             = new PrivacyUpgradeNotice($this->integrationHelper, $this->pluginModel);
    }

    #[DataProvider('updateVersionProvider')]
    public function testShouldPromptOnUpdate(string $oldVersion, bool $expected): void
    {
        $this->assertSame($expected, $this->notice->shouldPromptOnUpdate($oldVersion));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function updateVersionProvider(): iterable
    {
        yield '1.2.0' => ['1.2.0', true];
        yield '1.2.9' => ['1.2.9', true];
        yield '1.3.0' => ['1.3.0', false];
        yield 'later 1.x' => ['1.4.0', false];
    }

    public function testDisableUnpublishesAndMarksPending(): void
    {
        $entity = new Integration();
        $entity->setIsPublished(true);
        $entity->setFeatureSettings(['enable_page_hit_tracking' => true]);

        $this->integrationHelper->method('getIntegrationObject')
            ->with(EcommerceConnectorIntegration::NAME)
            ->willReturn($this->createIntegration($entity));

        $this->pluginModel->expects($this->once())
            ->method('saveFeatureSettings')
            ->with($entity);

        $this->notice->disableIntegrationAndShowNotice();

        $this->assertFalse($entity->getIsPublished());
        $this->assertSame(PrivacyUpgradeNotice::STATUS_PENDING, $entity->getFeatureSettings()[PrivacyUpgradeNotice::FEATURE_KEY]);
        $this->assertTrue($entity->getFeatureSettings()['enable_page_hit_tracking']);
        $this->assertTrue($this->notice->isPending());
    }

    public function testDismissStoresDismissedInFeatureSettings(): void
    {
        $entity = new Integration();
        $entity->setFeatureSettings([PrivacyUpgradeNotice::FEATURE_KEY => PrivacyUpgradeNotice::STATUS_PENDING]);

        $this->integrationHelper->method('getIntegrationObject')
            ->with(EcommerceConnectorIntegration::NAME)
            ->willReturn($this->createIntegration($entity));

        $this->pluginModel->expects($this->once())
            ->method('saveFeatureSettings')
            ->with($entity);

        $this->notice->dismiss();

        $this->assertSame(PrivacyUpgradeNotice::STATUS_DISMISSED, $entity->getFeatureSettings()[PrivacyUpgradeNotice::FEATURE_KEY]);
        $this->assertFalse($this->notice->isPending());
    }

    public function testIsPendingIsFalseWhenIntegrationIsMissing(): void
    {
        $this->integrationHelper->method('getIntegrationObject')->willReturn(false);
        $this->pluginModel->expects($this->never())->method('saveFeatureSettings');

        $this->assertFalse($this->notice->isPending());
        $this->notice->disableIntegrationAndShowNotice();
        $this->notice->dismiss();
    }

    private function createIntegration(Integration $entity): EcommerceConnectorIntegration&MockObject
    {
        $integration = $this->createMock(EcommerceConnectorIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($entity);

        return $integration;
    }
}
