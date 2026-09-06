<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class PrivacyUpdateNotifier
{
    public const DEDUPLICATE_KEY = 'ecommerce-connector-privacy-upgrade-1.3.0';

    public function __construct(
        private NotificationModel $notificationModel,
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
    ) {
    }

    public function notifyAdmins(): void
    {
        $message = $this->translator->trans('mautic.plugin.ecommerce.notification.message');
        $since   = new \DateTime('-10 years');

        foreach ($this->userRepository->getAllAdminUsers() as $adminUser) {
            if (!$adminUser instanceof User || !$adminUser->isPublished()) {
                continue;
            }

            $this->notificationModel->addNotification(
                $message,
                'info',
                false,
                'mautic.plugin.ecommerce.notification.header',
                'ri-shopping-cart-2-line',
                null,
                $adminUser,
                self::DEDUPLICATE_KEY,
                $since
            );
        }
    }
}
