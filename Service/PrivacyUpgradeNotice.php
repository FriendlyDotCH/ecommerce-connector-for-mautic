<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Model\PluginModel;
use MauticPlugin\EcommerceConnectorBundle\Integration\EcommerceConnectorIntegration;

class PrivacyUpgradeNotice
{
    public const FEATURE_KEY           = 'privacy_upgrade_notice';
    public const STATUS_PENDING        = 'pending';
    public const STATUS_DISMISSED      = 'dismissed';
    public const NOTICE_SINCE_VERSION = '1.3.0';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private PluginModel $pluginModel,
    ) {
    }

    public function shouldPromptOnUpdate(string $oldVersion): bool
    {
        return version_compare($oldVersion, self::NOTICE_SINCE_VERSION, '<');
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->getStatus();
    }

    public function disableIntegrationAndShowNotice(): void
    {
        $entity = $this->getIntegrationEntity();
        if (null === $entity) {
            return;
        }

        $entity->setIsPublished(false);
        $this->setStatus($entity, self::STATUS_PENDING);
        $this->pluginModel->saveFeatureSettings($entity);
    }

    public function dismiss(): void
    {
        $entity = $this->getIntegrationEntity();
        if (null === $entity) {
            return;
        }

        $this->setStatus($entity, self::STATUS_DISMISSED);
        $this->pluginModel->saveFeatureSettings($entity);
    }

    private function getStatus(): ?string
    {
        $entity = $this->getIntegrationEntity();
        if (null === $entity) {
            return null;
        }

        $status = $entity->getFeatureSettings()[self::FEATURE_KEY] ?? null;

        return is_string($status) ? $status : null;
    }

    private function setStatus(Integration $entity, string $status): void
    {
        $settings                     = $entity->getFeatureSettings();
        $settings[self::FEATURE_KEY] = $status;
        $entity->setFeatureSettings($settings);
    }

    private function getIntegrationEntity(): ?Integration
    {
        $integration = $this->integrationHelper->getIntegrationObject(EcommerceConnectorIntegration::NAME);
        if (!$integration instanceof EcommerceConnectorIntegration) {
            return null;
        }

        return $integration->getIntegrationSettings();
    }
}
