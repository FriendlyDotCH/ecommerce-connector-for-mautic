<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Event\PageHitEvent;
use MauticPlugin\EcommerceConnectorBundle\DTO\StorefrontEventPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\TrackingSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class TrackingSubscriberStorefrontTest extends TestCase
{
    private StorefrontEventRecorder&MockObject $recorder;

    private EcommerceOrderTracker&MockObject $orderTracker;

    private TrackingSubscriber $subscriber;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);
        $config->method('isPageHitTrackingEnabled')->willReturn(true);
        $config->method('getEventRetentionDays')->willReturn(90);
        $config->method('getMaxOrderTotal')->willReturn(1000000.0);

        $this->recorder     = $this->createMock(StorefrontEventRecorder::class);
        $this->orderTracker = $this->createMock(EcommerceOrderTracker::class);

        $this->subscriber = new TrackingSubscriber(
            new StorefrontEventParser($config, $this->createMock(LoggerInterface::class)),
            $this->recorder,
            $this->orderTracker,
            $this->createMock(OrderPayloadParser::class),
            $config,
            new ExistingContactLookup($this->createMock(LeadModel::class)),
        );
    }

    public function testPageviewWithStorefrontEventIsRecordedForTheHitContact(): void
    {
        $lead = new Lead();
        $lead->setId(5);

        $this->recorder->expects($this->once())
            ->method('record')
            ->with(
                $this->callback(static fn (StorefrontEventPayload $payload): bool => 'viewed_product' === $payload->eventType && 'CND-1' === $payload->productId),
                $lead
            );
        $this->orderTracker->expects($this->never())->method('recordOrder');

        $this->subscriber->onPageHit($this->hitEvent($lead, new Request([], [
            'ecommerce_event' => 'viewed_product',
            'product'         => '{"id":"CND-1","name":"Candle"}',
        ])));
    }

    public function testDoNotTrackAndGpcAreHonoured(): void
    {
        $lead = new Lead();
        $lead->setId(5);

        $this->recorder->expects($this->never())->method('record');

        foreach (['DNT', 'Sec-GPC'] as $header) {
            $request = new Request([], ['ecommerce_event' => 'viewed_product', 'product_id' => 'CND-1']);
            $request->headers->set($header, '1');
            $this->subscriber->onPageHit($this->hitEvent($lead, $request));
        }
    }

    private function hitEvent(Lead $lead, Request $request): PageHitEvent
    {
        $hit = new Hit();
        $hit->setLead($lead);
        $hit->setQuery([]);

        return new PageHitEvent($hit, $request, 200);
    }
}
