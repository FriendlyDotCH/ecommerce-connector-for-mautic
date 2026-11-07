<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldUpdater;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeadCommerceFieldUpdaterTest extends TestCase
{
    /** @var EcommerceOrderRepository&MockObject */
    private EcommerceOrderRepository $orderRepository;

    /** @var LeadModel&MockObject */
    private LeadModel $leadModel;

    private LeadCommerceFieldUpdater $updater;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EcommerceOrderRepository::class);
        $this->leadModel       = $this->createMock(LeadModel::class);
        $this->updater         = new LeadCommerceFieldUpdater($this->orderRepository, $this->leadModel);
    }

    public function testSyncForLeadSkipsWhenLeadHasNoId(): void
    {
        $lead = new Lead();

        $this->orderRepository->expects($this->never())->method('getCommerceStatsForLead');
        $this->leadModel->expects($this->never())->method('setFieldValues');

        $this->updater->syncForLead($lead);
    }

    public function testSyncForLeadUpdatesCommerceFields(): void
    {
        $lead = new Lead();
        $lead->setId(42);

        $this->orderRepository->expects($this->once())
            ->method('getCommerceStatsForLead')
            ->with(42)
            ->willReturn([
                'order_count'      => 2,
                'lifetime_value'   => '150.00',
                'last_order_date'  => '2026-06-01 12:00:00',
                'last_order_total' => '99.99',
            ]);

        $this->leadModel->expects($this->once())
            ->method('setFieldValues')
            ->with(
                $lead,
                [
                    LeadCommerceFieldDefinition::LIFETIME_VALUE    => '150.00',
                    LeadCommerceFieldDefinition::ORDER_COUNT       => 2,
                    LeadCommerceFieldDefinition::LAST_ORDER_TOTAL => '99.99',
                    LeadCommerceFieldDefinition::LAST_ORDER_DATE  => '2026-06-01 12:00:00',
                ],
                true
            );

        $this->leadModel->expects($this->once())
            ->method('saveEntity')
            ->with($lead, false);

        $this->updater->syncForLead($lead);
    }
}
