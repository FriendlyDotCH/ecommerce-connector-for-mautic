<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\LeadMergeEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\LeadLifecycleSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldUpdater;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeadLifecycleSubscriberTest extends TestCase
{
    private EcommerceOrderRepository&MockObject $orderRepository;

    private LeadCommerceFieldUpdater&MockObject $fieldUpdater;

    private Config&MockObject $config;

    private EcommerceEventRepository&MockObject $eventRepository;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EcommerceOrderRepository::class);
        $this->fieldUpdater   = $this->createMock(LeadCommerceFieldUpdater::class);
        $this->config         = $this->createMock(Config::class);
        $this->eventRepository = $this->createMock(EcommerceEventRepository::class);
    }

    public function testDeletesOrdersBeforeContactDelete(): void
    {
        $lead = new Lead();
        $lead->setId(42);

        $this->orderRepository->expects($this->once())
            ->method('deleteByLeadId')
            ->with(42);
        $this->eventRepository->expects($this->once())
            ->method('deleteByLeadId')
            ->with(42);

        $subscriber = new LeadLifecycleSubscriber($this->orderRepository, $this->fieldUpdater, $this->config, $this->eventRepository);
        $subscriber->onLeadPreDelete(new LeadEvent($lead));
    }

    public function testAnonymizesOrdersWhenConfigured(): void
    {
        $lead = new Lead();
        $lead->setId(42);

        $this->config->method('getOnContactDelete')->willReturn(Config::ON_CONTACT_DELETE_ANONYMIZE);
        $this->orderRepository->expects($this->once())->method('anonymizeByLeadId')->with(42);
        $this->orderRepository->expects($this->never())->method('deleteByLeadId');

        $subscriber = new LeadLifecycleSubscriber($this->orderRepository, $this->fieldUpdater, $this->config, $this->eventRepository);
        $subscriber->onLeadPreDelete(new LeadEvent($lead));
    }

    public function testSkipsDeleteWhenLeadHasNoId(): void
    {
        $this->orderRepository->expects($this->never())->method('deleteByLeadId');

        $subscriber = new LeadLifecycleSubscriber($this->orderRepository, $this->fieldUpdater, $this->config, $this->eventRepository);
        $subscriber->onLeadPreDelete(new LeadEvent(new Lead()));
    }

    public function testReassignsOrdersOnMerge(): void
    {
        $loser = new Lead();
        $loser->setId(7);
        $victor = new Lead();
        $victor->setId(9);

        $this->orderRepository->expects($this->once())
            ->method('updateLead')
            ->with(7, 9);
        $this->eventRepository->expects($this->once())
            ->method('updateLead')
            ->with(7, 9);
        $this->fieldUpdater->expects($this->once())
            ->method('syncForLead')
            ->with($victor);

        $subscriber = new LeadLifecycleSubscriber($this->orderRepository, $this->fieldUpdater, $this->config, $this->eventRepository);
        $subscriber->onLeadMerge(new LeadMergeEvent($victor, $loser));
    }
}
