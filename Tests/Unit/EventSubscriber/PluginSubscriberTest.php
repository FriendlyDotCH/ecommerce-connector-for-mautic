<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Mautic\PluginBundle\Bundle\PluginDatabase;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
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

    private EntityManagerInterface&MockObject $entityManager;

    private PluginSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->entityManager           = $this->createMock(EntityManagerInterface::class);
        $this->fieldInstaller          = $this->createMock(LeadCommerceFieldInstaller::class);
        $this->fieldUpdater            = $this->createMock(LeadCommerceFieldUpdater::class);
        $this->privacyUpdateNotifier   = $this->createMock(PrivacyUpdateNotifier::class);
        $this->privacyUpgradeNotice    = $this->createMock(PrivacyUpgradeNotice::class);

        $this->subscriber = new PluginSubscriber(
            $this->entityManager,
            (new \ReflectionClass(PluginDatabase::class))->newInstanceWithoutConstructor(),
            $this->fieldInstaller,
            $this->fieldUpdater,
            $this->privacyUpdateNotifier,
            $this->privacyUpgradeNotice,
        );
    }

    public function testRunsAfterCoreSchemaAndMigrations(): void
    {
        $events = PluginSubscriber::getSubscribedEvents();

        $this->assertLessThan(0, $events[PluginEvents::ON_PLUGIN_INSTALL][1]);
        $this->assertLessThan(0, $events[PluginEvents::ON_PLUGIN_UPDATE][1]);
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

        // All plugin tables already exist, so no schema is installed.
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn(new Schema());
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $plugin = new Plugin();
        $plugin->setBundle('EcommerceConnectorBundle');

        $this->subscriber->onInstall(new PluginInstallEvent($plugin, [], null));
    }

    private function createUpdateEvent(string $bundle, string $oldVersion): PluginUpdateEvent
    {
        $plugin = new Plugin();
        $plugin->setBundle($bundle);

        return new PluginUpdateEvent($plugin, $oldVersion, [], null);
    }
}
