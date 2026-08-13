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
            $logger->debug('Ecommerce webhook rejected because the integration is not published.');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $secret = $config->getWebhookSecret();
        if (null === $secret || !$signatureValidator->isValid($request, $secret)) {
            $logger->debug('Ecommerce webhook rejected due to missing or invalid signature.');

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
