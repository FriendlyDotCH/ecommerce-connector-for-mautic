<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller\Api;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventRecorder;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Storefront events under /api: create one or a batch, and list a contact's events.
 */
#[AsController]
class EventApiController
{
    public const MAX_BATCH = 100;

    public function __construct(
        private CorePermissions $security,
        private Config $config,
        private StorefrontEventParser $parser,
        private StorefrontEventRecorder $recorder,
        private EcommerceEventRepository $eventRepository,
        private ExistingContactLookup $existingContactLookup,
        private LeadModel $leadModel,
    ) {
    }

    public function newAction(Request $request): Response
    {
        if (!$this->security->isGranted('lead:leads:editother')) {
            return $this->error('You do not have access to create events.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->config->isStorefrontEventsEnabled()) {
            return $this->error('Storefront events are switched off in the plugin settings.', Response::HTTP_CONFLICT);
        }

        $input = WebhookRequestBody::getInput($request);
        $batch = isset($input['events']) && is_array($input['events']);
        $items = $batch ? array_slice($input['events'], 0, self::MAX_BATCH) : [$input];

        $results = [];
        foreach ($items as $index => $item) {
            $results[] = $this->recordOne(is_array($item) ? $item : [], $index);
        }

        if (!$batch) {
            $result = $results[0];

            return isset($result['error'])
                ? $this->error($result['error'], Response::HTTP_BAD_REQUEST)
                : new JsonResponse(['event' => $result['event']], Response::HTTP_CREATED);
        }

        return new JsonResponse(['events' => $results], Response::HTTP_CREATED);
    }

    public function contactEventsAction(Request $request, int $id): Response
    {
        $lead = $this->leadModel->getEntity($id);
        if (!$lead instanceof Lead) {
            return $this->error('Contact not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->security->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $lead->getPermissionUser())) {
            return $this->error('You do not have access to this contact.', Response::HTTP_FORBIDDEN);
        }

        $limit  = max(1, min(200, $request->query->getInt('limit', 30)));
        $result = $this->eventRepository->findForLead($id, max(0, $request->query->getInt('start')), $limit);

        return new JsonResponse([
            'total'  => $result['total'],
            'events' => array_map($this->normalize(...), $result['events']),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function recordOne(array $input, int $index): array
    {
        $payload = $this->parser->parse($input, 'api');
        if (null === $payload) {
            return ['index' => $index, 'error' => 'Invalid event; see the README for the accepted fields.'];
        }

        $contactId = isset($input['contact_id']) && is_numeric($input['contact_id']) ? (int) $input['contact_id'] : 0;
        $lead      = $contactId > 0 ? $this->leadModel->getEntity($contactId) : $this->existingContactLookup->findByEmail($input['email'] ?? null);

        $event = $this->recorder->record($payload, $lead instanceof Lead ? $lead : null);

        return null !== $event
            ? ['index' => $index, 'event' => $this->normalize($event)]
            : ['index' => $index, 'error' => 'No identified contact for this event.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(EcommerceEvent $event): array
    {
        return [
            'id'           => $event->getId(),
            'contact_id'   => $event->getLead()->getId(),
            'event'        => $event->getEventType(),
            'occurred_at'  => \DateTimeImmutable::createFromInterface($event->getOccurredAt())->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'source'       => $event->getSource(),
            'product'      => [
                'id'        => $event->getProductId(),
                'name'      => $event->getProductName(),
                'category'  => $event->getCategory(),
                'price'     => $event->getPrice(),
                'url'       => $event->getProductUrl(),
                'image_url' => $event->getImageUrl(),
            ],
            'cart'         => [
                'id'    => $event->getCartId(),
                'total' => $event->getCartValue(),
                'url'   => $event->getCartUrl(),
                'items' => $event->getCartItems() ?? [],
            ],
            'currency'     => $event->getCurrency(),
            'abandoned_at' => $event->getAbandonedAt()?->format(\DATE_ATOM),
        ];
    }

    private function error(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => $code, 'message' => $message, 'details' => [], 'type' => null]]], $code);
    }
}
