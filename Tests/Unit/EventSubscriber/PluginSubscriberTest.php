<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Bundle\PluginDatabase;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\PluginSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldUpdater;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpdateNotifier;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PluginSubscriberTest extends TestCase
{
    private LeadCommerceFieldInstaller&MockObject $fieldInstaller;

    private LeadCommerceFieldUpdater&MockObject $fieldUpdater;

    private PrivacyUpdateNotifier&MockObject $privacyUpdateNotifier;

    private PrivacyUpgradeNotice&MockObject $privacyUpgradeNotice;

    private PluginSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->fieldInstaller          = $this->createMock(LeadCommerceFieldInstaller::class);
        $this->fieldUpdater            = $this->createMock(LeadCommerceFieldUpdater::class);
        $this->privacyUpdateNotifier   = $this->createMock(PrivacyUpdateNotifier::class);
        $this->privacyUpgradeNotice    = $this->createMock(PrivacyUpgradeNotice::class);

        $this->subscriber = new PluginSubscriber(
            $this->createMock(EntityManagerInterface::class),
            (new \ReflectionClass(PluginDatabase::class))->newInstanceWithoutConstructor(),
            $this->fieldInstaller,
            $this->fieldUpdater,
            $this->privacyUpdateNotifier,
            $this->privacyUpgradeNotice,
        );
    }

    public function testUpdateFromOlderVersionDisablesIntegrationAndNotifies(): void
    {
        $this->privacyUpgradeNotice->method('shouldPromptOnUpdate')->with('1.2.0')->willReturn(true);
        $this->fieldInstaller->expects($this->once())->method('installFields');
        $this->fieldUpdater->expects($this->once())->method('backfillAllContacts');
        $this->privacyUpgradeNotice->expects($this->once())->method('disableIntegrationAndShowNotice');
        $this->privacyUpdateNotifier->expects($this->once())->method('notifyAdmins');

        $this->subscriber->onUpdate($this->createUpdateEvent('EcommerceConnectorBundle', '1.2.0'));
    }

    public function testUpdateFromCurrentVersionDoesNotDisableOrNotify(): void
    {
        $this->privacyUpgradeNotice->method('shouldPromptOnUpdate')->with('1.3.0')->willReturn(false);
        $this->fieldInstaller->expects($this->once())->method('installFields');
        $this->fieldUpdater->expects($this->once())->method('backfillAllContacts');
        $this->privacyUpgradeNotice->expects($this->never())->method('disableIntegrationAndShowNotice');
        $this->privacyUpdateNotifier->expects($this->never())->method('notifyAdmins');

        $this->subscriber->onUpdate($this->createUpdateEvent('EcommerceConnectorBundle', '1.3.0'));
    }

    public function testInstallDoesNotDisableOrNotify(): void
    {
        $this->fieldInstaller->expects($this->once())->method('installFields');
        $this->fieldUpdater->expects($this->once())->method('backfillAllContacts');
        $this->privacyUpgradeNotice->expects($this->never())->method('disableIntegrationAndShowNotice');
        $this->privacyUpgradeNotice->expects($this->never())->method('shouldPromptOnUpdate');
        $this->privacyUpdateNotifier->expects($this->never())->method('notifyAdmins');

        $plugin = new Plugin();
        $plugin->setBundle('EcommerceConnectorBundle');

        $this->subscriber->onInstall(new PluginInstallEvent($plugin, [], null));
    }

    private function createUpdateEvent(string $bundle, string $oldVersion): PluginUpdateEvent
    {
        $plugin = new Plugin();
        $plugin->setBundle($bundle);

        return new PluginUpdateEvent($plugin, $oldVersion, null, null);
    }
}
