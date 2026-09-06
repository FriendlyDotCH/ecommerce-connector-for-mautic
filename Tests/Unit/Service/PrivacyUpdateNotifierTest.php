<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpdateNotifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class PrivacyUpdateNotifierTest extends TestCase
{
    private NotificationModel&MockObject $notificationModel;

    private UserRepository&MockObject $userRepository;

    private TranslatorInterface&MockObject $translator;

    protected function setUp(): void
    {
        $this->notificationModel = $this->createMock(NotificationModel::class);
        $this->userRepository    = $this->createMock(UserRepository::class);
        $this->translator        = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
    }

    public function testNotifiesPublishedAdmins(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('isPublished')->willReturn(true);

        $unpublished = $this->createMock(User::class);
        $unpublished->method('isPublished')->willReturn(false);

        $this->userRepository->method('getAllAdminUsers')->willReturn([$admin, $unpublished]);
        $this->notificationModel->expects($this->once())
            ->method('addNotification')
            ->with(
                'mautic.plugin.ecommerce.notification.message',
                'info',
                false,
                'mautic.plugin.ecommerce.notification.header',
                'ri-shopping-cart-2-line',
                null,
                $admin,
                PrivacyUpdateNotifier::DEDUPLICATE_KEY,
                $this->isInstanceOf(\DateTime::class)
            );

        $notifier = new PrivacyUpdateNotifier(
            $this->notificationModel,
            $this->userRepository,
            $this->translator
        );
        $notifier->notifyAdmins();
    }
}
