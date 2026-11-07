<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonController
{
    public function ecommerceAction(
        Request $request,
        ExistingContactLookup $existingContactLookup,
        EcommerceOrderTracker $orderTracker,
        OrderPayloadParser $payloadParser,
        WebhookSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
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
}
