<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\AdminNoticeSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminNoticeSubscriberTest extends TestCase
{
    private PrivacyUpgradeNotice&MockObject $privacyUpgradeNotice;

    private CorePermissions&MockObject $security;

    private AdminNoticeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->privacyUpgradeNotice = $this->createMock(PrivacyUpgradeNotice::class);
        $this->security             = $this->createMock(CorePermissions::class);
        $this->subscriber         = new AdminNoticeSubscriber($this->privacyUpgradeNotice, $this->security);
    }

    public function testInjectsNoticeOnDashboardWhenPending(): void
    {
        $this->security->method('isGranted')->with('plugin:plugins:manage')->willReturn(true);
        $this->privacyUpgradeNotice->method('isPending')->willReturn(true);

        $event = new CustomContentEvent('@MauticDashboard/Dashboard/index.html.twig', 'page.header.left');
        $this->subscriber->onInjectCustomContent($event);

        $templates = $event->getTemplates();
        $this->assertCount(1, $templates);
        $this->assertSame('@EcommerceConnector/Notification/admin_notice.html.twig', $templates[0]['template']);
    }

    public function testDoesNotInjectWhenNoticeIsNotPending(): void
    {
        $this->security->method('isGranted')->with('plugin:plugins:manage')->willReturn(true);
        $this->privacyUpgradeNotice->method('isPending')->willReturn(false);

        $event = new CustomContentEvent('@MauticDashboard/Dashboard/index.html.twig', 'page.header.left');
        $this->subscriber->onInjectCustomContent($event);

        $this->assertSame([], $event->getTemplates());
    }

    public function testDoesNotInjectWithoutPluginManagePermission(): void
    {
        $this->security->method('isGranted')->with('plugin:plugins:manage')->willReturn(false);
        $this->privacyUpgradeNotice->expects($this->never())->method('isPending');

        $event = new CustomContentEvent('@MauticDashboard/Dashboard/index.html.twig', 'page.header.left');
        $this->subscriber->onInjectCustomContent($event);

        $this->assertSame([], $event->getTemplates());
    }

    public function testDoesNotInjectOnOtherPages(): void
    {
        $this->security->expects($this->never())->method('isGranted');
        $this->privacyUpgradeNotice->expects($this->never())->method('isPending');

        $event = new CustomContentEvent('@MauticLead/Lead/index.html.twig', 'page.header.left');
        $this->subscriber->onInjectCustomContent($event);

        $this->assertSame([], $event->getTemplates());
    }
}
