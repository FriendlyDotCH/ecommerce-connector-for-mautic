<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AdminNoticeSubscriber implements EventSubscriberInterface
{
    private const DASHBOARD_VIEW = '@MauticDashboard/Dashboard/index.html.twig';

    public function __construct(
        private PrivacyUpgradeNotice $privacyUpgradeNotice,
        private CorePermissions $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onInjectCustomContent', 0],
        ];
    }

    public function onInjectCustomContent(CustomContentEvent $event): void
    {
        if (!$event->checkContext(self::DASHBOARD_VIEW, 'page.header.left')) {
            return;
        }

        if (true !== $this->security->isGranted('plugin:plugins:manage')) {
            return;
        }

        if (!$this->privacyUpgradeNotice->isPending()) {
            return;
        }

        $event->addTemplate('@EcommerceConnector/Notification/admin_notice.html.twig');
    }
}
