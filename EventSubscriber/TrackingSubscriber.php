<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\Event\TrackingEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TrackingSubscriber implements EventSubscriberInterface
{
    private const TRACKING_ROUTES = [
        'mautic_page_tracker_cors',
        'mautic_page_tracker',
    ];

    public function __construct(
        private EcommerceOrderTracker $orderTracker,
        private OrderPayloadParser $payloadParser,
        private Config $config,
        private ExistingContactLookup $existingContactLookup,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST            => ['onKernelRequest', -10],
            PageEvents::PAGE_ON_HIT          => ['onPageHit', 0],
            PageEvents::ON_CONTACT_TRACKED   => ['onContactTracked', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array((string) $request->attributes->get('_route'), self::TRACKING_ROUTES, true)) {
            return;
        }

        $this->recordFromInput($this->extractInput($request), null);
    }

    public function onPageHit(PageHitEvent $event): void
    {
        $request = $event->getRequest();
        $hit     = $event->getHit();
        $lead    = $hit->getLead();

        $this->recordFromInput(
            $this->extractInput($request instanceof Request ? $request : null, $hit->getQuery() ?? []),
            $this->usableLead($lead),
            $hit->getEmail()
        );
    }

    public function onContactTracked(TrackingEvent $event): void
    {
        $this->recordFromInput(
            $this->extractInput($event->getRequest()),
            $this->usableLead($event->getContact())
        );
    }

    /**
     * @param array<string, mixed> $hitQuery
     *
     * @return array<string, mixed>
     */
    private function extractInput(?Request $request, array $hitQuery = []): array
    {
        $input = $hitQuery;

        if ($request instanceof Request) {
            $input = array_merge($input, $request->query->all(), $request->request->all());
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function recordFromInput(array $input, ?Lead $lead, ?Email $email = null): void
    {
        if (!$this->config->isPublished() || !$this->config->isPageHitTrackingEnabled()) {
            return;
        }

        $payload = $this->payloadParser->parse($input, 'page_hit');
        if (null === $payload) {
            return;
        }

        if (null === $lead) {
            $lead = $this->existingContactLookup->findByEmail($input['email'] ?? null);
        }

        $this->orderTracker->recordOrder($payload, $lead, $email);
    }

    private function usableLead(?Lead $lead): ?Lead
    {
        return $lead instanceof Lead && $lead->getId() ? $lead : null;
    }
}
