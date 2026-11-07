<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\PluginBundle\Bundle\PluginDatabase;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldUpdater;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpdateNotifier;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginSubscriber implements EventSubscriberInterface
{
    private const BUNDLE = 'EcommerceConnectorBundle';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PluginDatabase $pluginDatabase,
        private LeadCommerceFieldInstaller $leadCommerceFieldInstaller,
        private LeadCommerceFieldUpdater $leadCommerceFieldUpdater,
        private PrivacyUpdateNotifier $privacyUpdateNotifier,
        private PrivacyUpgradeNotice $privacyUpgradeNotice,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Both run after core's PluginSubscriber (priority 0), which installs the schema and
            // applies Migrations; field backfills read columns those migrations add.
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', -100],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onUpdate', -100],
        ];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        // Runs after core's PluginSubscriber. self::getMetadata() excludes tables that
        // already exist, so this only fills gaps core did not create.
        $metadata = self::getMetadata($this->entityManager);

        if (count($metadata) > 0) {
            $this->pluginDatabase->installPluginSchema($metadata, null);
        }

        $this->leadCommerceFieldInstaller->installFields();
        $this->leadCommerceFieldUpdater->backfillAllContacts();
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        // Schema changes for upgrades are handled by Migrations (via core PluginSubscriber).
        $this->leadCommerceFieldInstaller->installFields();
        $this->leadCommerceFieldUpdater->backfillAllContacts();

        if ($this->privacyUpgradeNotice->shouldPromptOnUpdate($event->getOldVersion())) {
            $this->privacyUpgradeNotice->disableIntegrationAndShowNotice();
            $this->privacyUpdateNotifier->notifyAdmins();
        }
    }

    /**
     * @return array<class-string, ClassMetadata>
     */
    private static function getMetadata(EntityManagerInterface $em): array
    {
        $allMetadata   = $em->getMetadataFactory()->getAllMetadata();
        $currentSchema = $em->getConnection()->createSchemaManager()->introspectSchema();

        $classes = [];

        /** @var ClassMetadata $meta */
        foreach ($allMetadata as $meta) {
            if (!str_contains($meta->namespace, 'MauticPlugin\\EcommerceConnectorBundle')) {
                continue;
            }

            $table = $meta->getTableName();

            if ($currentSchema->hasTable($table)) {
                continue;
            }

            $classes[$meta->getName()] = $meta;
        }

        return $classes;
    }
}
