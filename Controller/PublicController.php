<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderManager;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventRecorder;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use MauticPlugin\EcommerceConnectorBundle\Service\WooCommerceOrderMapper;
use MauticPlugin\EcommerceConnectorBundle\Service\WooCommerceSignatureValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonController
{
    public const MAX_EVENTS_PER_REQUEST = 100;

    public function ecommerceAction(
        Request $request,
        ExistingContactLookup $existingContactLookup,
        EcommerceOrderTracker $orderTracker,
        OrderPayloadParser $payloadParser,
        WebhookSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        $rejected = $this->rejectUnlessSigned($request, $config, $signatureValidator, $logger);
        if (null !== $rejected) {
            return $rejected;
        }

        $input = WebhookRequestBody::getInput($request);
        $payload = $payloadParser->parse($input, 'webhook');
        if (null === $payload) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Link only when email matches an existing contact. Never create contacts here.
        $lead = $existingContactLookup->findByEmail($input['email'] ?? null);

        $orderTracker->recordOrder($payload, $lead);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Storefront events from the store: one event, or {"events": [...]}. Signed like orders.
     */
    public function eventAction(
        Request $request,
        ExistingContactLookup $existingContactLookup,
        StorefrontEventParser $eventParser,
        StorefrontEventRecorder $eventRecorder,
        WebhookSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        $rejected = $this->rejectUnlessSigned($request, $config, $signatureValidator, $logger);
        if (null !== $rejected) {
            return $rejected;
        }

        $input  = WebhookRequestBody::getInput($request);
        $events = isset($input['events']) && is_array($input['events']) ? $input['events'] : [$input];

        foreach (array_slice($events, 0, self::MAX_EVENTS_PER_REQUEST) as $eventInput) {
            if (!is_array($eventInput)) {
                continue;
            }

            $payload = $eventParser->parse($eventInput, 'webhook');
            if (null !== $payload) {
                // Existing contacts only; never created from events.
                $eventRecorder->record($payload, $existingContactLookup->findByEmail($eventInput['email'] ?? $input['email'] ?? null));
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Native WooCommerce order webhooks (WooCommerce > Settings > Advanced > Webhooks, topics
     * Order created / updated / restored), signed with the same webhook secret.
     */
    public function woocommerceAction(
        Request $request,
        ExistingContactLookup $existingContactLookup,
        EcommerceOrderTracker $orderTracker,
        EcommerceOrderRepository $orderRepository,
        OrderManager $orderManager,
        OrderPayloadParser $payloadParser,
        WooCommerceSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        if (!$config->isPublished() || null === ($secret = $config->getWebhookSecret())) {
            $logger->warning('WooCommerce webhook rejected: the integration is not published or has no webhook secret.');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (!$signatureValidator->isValid($request, $secret)) {
            $logger->warning('WooCommerce webhook rejected: missing or invalid X-WC-Webhook-Signature. Use the plugin\'s webhook secret as the webhook secret in WooCommerce.', [
                'topic' => (string) $request->headers->get('X-WC-Webhook-Topic', ''),
            ]);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $body = json_decode($request->getContent(), true);

        // WooCommerce pings a new webhook with "webhook_id=…"; answering 2xx activates it.
        if (!is_array($body)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $input = WooCommerceOrderMapper::map($body);
        if (null === $input) {
            $logger->notice('WooCommerce order skipped: not paid yet or not an order.', ['order' => $body['id'] ?? null, 'status' => $body['status'] ?? null]);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $payload = $payloadParser->parse($input, 'woocommerce');
        if (null === $payload) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // order.updated arrives on every change; only status and refunds matter for known orders.
        $existing = $orderRepository->findOneBy(['orderId' => $payload->orderId, 'orderSource' => $payload->orderSource]);
        if ($existing instanceof EcommerceOrder) {
            $orderManager->applyRefunds($existing, $payload);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $orderTracker->recordOrder($payload, $existingContactLookup->findByEmail($input['email']));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Rejections answer 204 like successes so the endpoint cannot be probed; the reason is logged.
     */
    private function rejectUnlessSigned(Request $request, Config $config, WebhookSignatureValidator $signatureValidator, LoggerInterface $logger): ?Response
    {
        if (!$config->isPublished()) {
            // Warnings, so the reason shows in Mautic's log while the response stays 204.
            $logger->warning('Ecommerce webhook rejected: the integration is not published.');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $secret = $config->getWebhookSecret();
        if (null === $secret) {
            $logger->warning('Ecommerce webhook rejected: no webhook secret is configured.');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (!$signatureValidator->isValid($request, $secret)) {
            // Length and hash of the received body let senders check they signed the same bytes.
            $logger->warning(sprintf(
                'Ecommerce webhook rejected: %s X-Mautic-Ecommerce-Signature header. For a JSON body, sign the exact raw body with HMAC-SHA256 and the webhook secret.',
                '' === (string) $request->headers->get('X-Mautic-Ecommerce-Signature', '') ? 'missing' : 'invalid'
            ), [
                'content_type' => (string) $request->headers->get('Content-Type', ''),
                'body_length'  => strlen($request->getContent()),
                'body_sha256'  => hash('sha256', $request->getContent()),
            ]);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return null;
    }
}
