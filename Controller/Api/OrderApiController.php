<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller\Api;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderManager;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderNormalizer;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * REST API for orders under /api. Orders belong to contacts, so contact permissions apply:
 * reading needs "view others", writing needs "edit others". Contacts are never created.
 */
#[AsController]
class OrderApiController
{
    public const MAX_LIMIT = 200;

    public function __construct(
        private CorePermissions $security,
        private EcommerceOrderRepository $orderRepository,
        private OrderPayloadParser $payloadParser,
        private EcommerceOrderTracker $orderTracker,
        private OrderManager $orderManager,
        private OrderNormalizer $normalizer,
        private ExistingContactLookup $existingContactLookup,
        private LeadModel $leadModel,
    ) {
    }

    public function listAction(Request $request): Response
    {
        if (!$this->security->isGranted('lead:leads:viewother')) {
            return $this->error('You do not have access to orders.', Response::HTTP_FORBIDDEN);
        }

        $since = null;
        if ('' !== (string) $request->query->get('since', '')) {
            try {
                $since = new \DateTimeImmutable((string) $request->query->get('since'), new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return $this->error('since must be a date.', Response::HTTP_BAD_REQUEST);
            }
        }

        $contactId = $request->query->getInt('contact_id');
        $source    = trim((string) $request->query->get('source', ''));

        return $this->listResponse($request, $contactId > 0 ? $contactId : null, '' !== $source ? $source : null, $since);
    }

    public function contactOrdersAction(Request $request, int $id): Response
    {
        $lead = $this->leadModel->getEntity($id);
        if (!$lead instanceof Lead) {
            return $this->error('Contact not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->security->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $lead->getPermissionUser())) {
            return $this->error('You do not have access to this contact.', Response::HTTP_FORBIDDEN);
        }

        return $this->listResponse($request, $id, null, null);
    }

    public function getAction(int $id): Response
    {
        if (!$this->security->isGranted('lead:leads:viewother')) {
            return $this->error('You do not have access to orders.', Response::HTTP_FORBIDDEN);
        }

        $order = $this->orderRepository->find($id);
        if (!$order instanceof EcommerceOrder) {
            return $this->error('Order not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['order' => $this->normalizer->normalize($order)]);
    }

    public function newAction(Request $request): Response
    {
        if (!$this->security->isGranted('lead:leads:editother')) {
            return $this->error('You do not have access to create orders.', Response::HTTP_FORBIDDEN);
        }

        $input   = WebhookRequestBody::getInput($request);
        $payload = $this->payloadParser->parse($input, 'api');
        if (null === $payload) {
            return $this->error('Invalid order. order_id is required; see the README for the accepted fields.', Response::HTTP_BAD_REQUEST);
        }

        $lead = $this->resolveContact($input);
        if ($lead instanceof Response) {
            return $lead;
        }

        if ($this->orderRepository->orderExists($payload->orderId, $payload->orderSource)) {
            $existing = $this->orderRepository->findOneBy(['orderId' => $payload->orderId, 'orderSource' => $payload->orderSource]);

            return $this->error('An order with this order_id and order_source already exists.', Response::HTTP_CONFLICT, [
                'id' => $existing?->getId(),
            ]);
        }

        $order = $this->orderTracker->recordOrder($payload, $lead);
        if (null === $order) {
            return $this->error('An order with this order_id and order_source already exists.', Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['order' => $this->normalizer->normalize($order)], Response::HTTP_CREATED);
    }

    public function editAction(Request $request, int $id): Response
    {
        if (!$this->security->isGranted('lead:leads:editother')) {
            return $this->error('You do not have access to edit orders.', Response::HTTP_FORBIDDEN);
        }

        $order = $this->orderRepository->find($id);
        if (!$order instanceof EcommerceOrder) {
            return $this->error('Order not found.', Response::HTTP_NOT_FOUND);
        }

        $changes = WebhookRequestBody::getInput($request);
        unset($changes['order_id'], $changes['order_source']);

        // PATCH keeps what is not sent; PUT replaces the editable fields.
        $current = Request::METHOD_PATCH === $request->getMethod() ? $this->normalizer->normalize($order) : [];
        $input   = array_merge($current, $changes, [
            'order_id'     => $order->getOrderId(),
            'order_source' => $order->getOrderSource(),
        ]);

        $payload = $this->payloadParser->parse($input, 'api');
        if (null === $payload) {
            return $this->error('Invalid order data.', Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('contact_id', $changes) || array_key_exists('email', $changes)) {
            $lead = $this->resolveContact($changes);
            if ($lead instanceof Response) {
                return $lead;
            }
        } else {
            $lead = $order->getLead();
        }

        $this->orderManager->update($order, $payload, $lead);

        return new JsonResponse(['order' => $this->normalizer->normalize($order)]);
    }

    public function deleteAction(int $id): Response
    {
        if (!$this->security->isGranted('lead:leads:editother')) {
            return $this->error('You do not have access to delete orders.', Response::HTTP_FORBIDDEN);
        }

        $order = $this->orderRepository->find($id);
        if (!$order instanceof EcommerceOrder) {
            return $this->error('Order not found.', Response::HTTP_NOT_FOUND);
        }

        $data       = $this->normalizer->normalize($order);
        $this->orderManager->delete($order);

        return new JsonResponse(['order' => $data]);
    }

    private function listResponse(Request $request, ?int $leadId, ?string $source, ?\DateTimeInterface $since): Response
    {
        $limit = $request->query->getInt('limit', 30);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $start = max(0, $request->query->getInt('start'));

        $result = $this->orderRepository->findForApi($leadId, $source, $since, $start, $limit);

        return new JsonResponse([
            'total'  => $result['total'],
            'orders' => array_map($this->normalizer->normalize(...), $result['orders']),
        ]);
    }

    /**
     * contact_id must be an existing contact; email links an existing contact only.
     *
     * @param array<string, mixed> $input
     */
    private function resolveContact(array $input): Lead|Response|null
    {
        $contactId = isset($input['contact_id']) && is_numeric($input['contact_id']) ? (int) $input['contact_id'] : 0;

        if ($contactId > 0) {
            $lead = $this->leadModel->getEntity($contactId);

            return $lead instanceof Lead
                ? $lead
                : $this->error('Contact not found.', Response::HTTP_BAD_REQUEST, ['contact_id' => $contactId]);
        }

        return $this->existingContactLookup->findByEmail($input['email'] ?? null);
    }

    /**
     * Same error shape as Mautic's core API.
     *
     * @param array<string, mixed> $details
     */
    private function error(string $message, int $code, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'code'    => $code,
                'message' => $message,
                'details' => $details,
                'type'    => null,
            ]],
        ], $code);
    }
}
