<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Platform\PlatformRegistry;
use MauticPlugin\EcommerceConnectorBundle\Platform\ShopifyPlatform;
use MauticPlugin\EcommerceConnectorBundle\Platform\StorePlatformInterface;
use MauticPlugin\EcommerceConnectorBundle\Service\CatalogSync;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderManager;
use MauticPlugin\EcommerceConnectorBundle\Service\OrderPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductPayloadParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventRecorder;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookRequestBody;
use MauticPlugin\EcommerceConnectorBundle\Service\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonController
{
    public const MAX_EVENTS_PER_REQUEST = 100;

    public const MAX_PRODUCTS_PER_REQUEST = 250;

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
                // Existing contacts only; never created from events. Without an email, a guest who
                // arrived from a Mautic email is identified by that send's tracking hash.
                $contact = $existingContactLookup->findByEmail($eventInput['email'] ?? $input['email'] ?? null)
                    ?? $existingContactLookup->findByEmailStat(ExistingContactLookup::emailStatFrom($eventInput) ?? ExistingContactLookup::emailStatFrom($input));
                $eventRecorder->record($payload, $contact);
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Catalog updates from store connectors, in the standard format: one product,
     * {"products": [...]}, and/or {"store": "...", "deleted": ["id", ...]}. Signed like orders.
     */
    public function productAction(
        Request $request,
        ProductPayloadParser $productParser,
        CatalogSync $catalogSync,
        WebhookSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        $rejected = $this->rejectUnlessSigned($request, $config, $signatureValidator, $logger);
        if (null !== $rejected) {
            return $rejected;
        }

        $input    = WebhookRequestBody::getInput($request);
        $products = isset($input['products']) && is_array($input['products']) ? $input['products'] : (isset($input['id']) ? [$input] : []);
        $leadIds  = [];

        foreach (array_slice($products, 0, self::MAX_PRODUCTS_PER_REQUEST) as $productInput) {
            if (!is_array($productInput)) {
                continue;
            }

            // Batch items inherit the request's store.
            $productInput += ['store' => $input['store'] ?? null];

            if (!empty($productInput['deleted'])) {
                $store = $productParser->store($productInput['store']);
                $id    = $productInput['id'] ?? null;
                if (null !== $store && is_scalar($id) && '' !== (string) $id) {
                    $catalogSync->delete($store, (string) $id);
                }
                continue;
            }

            $parsed = $productParser->parse($productInput, 'webhook');
            if (null !== $parsed) {
                $leadIds = [...$leadIds, ...$catalogSync->upsert($parsed['store'], $parsed['product'], false)];
            }
        }

        $deleteStore = $productParser->store($input['store'] ?? null);
        foreach (is_array($input['deleted'] ?? null) ? $input['deleted'] : [] as $id) {
            if (null !== $deleteStore && is_scalar($id) && '' !== (string) $id) {
                $catalogSync->delete($deleteStore, (string) $id);
            }
        }

        // Once per request, not per product.
        $catalogSync->recalculate($leadIds);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Native shop platform webhooks: /ecommerce/webhook/{platform} (and /ecommerce/woocommerce),
     * signed with the plugin's webhook secret. Orders, refunds, cancellations and products.
     */
    public function platformWebhookAction(
        Request $request,
        string $platform,
        PlatformRegistry $platformRegistry,
        ExistingContactLookup $existingContactLookup,
        EcommerceOrderTracker $orderTracker,
        EcommerceOrderRepository $orderRepository,
        OrderManager $orderManager,
        OrderPayloadParser $payloadParser,
        CatalogSync $catalogSync,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        $adapter = $platformRegistry->getPlatform($platform);
        if (null === $adapter) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $name = $adapter->getName();
        if (!$config->isPublished() || null === ($secret = $adapter->getSigningSecret($config))) {
            $logger->warning(sprintf('%s webhook rejected: the integration is not published or has no webhook secret.', $name));

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (!$adapter->isSignatureValid($request, $secret)) {
            $logger->warning(sprintf(
                ShopifyPlatform::NAME === $name
                    ? '%s webhook rejected: missing or invalid signature. Enter the key Shopify signs webhooks with (Settings > Notifications > Webhooks) as "Shopify webhook signing secret".'
                    : '%s webhook rejected: missing or invalid signature. Use the plugin\'s webhook secret as the webhook secret in the shop.',
                $name
            ));

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $body     = json_decode($request->getContent(), true);
        $body     = is_array($body) ? $body : null;
        $resource = $adapter->getResource($request, $body);

        if (null === $body || null === $resource || StorePlatformInterface::RESOURCE_PING === $resource) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (StorePlatformInterface::RESOURCE_PRODUCT === $resource) {
            $product = $adapter->mapProduct($body, $request);
            if (null !== $product) {
                $catalogSync->upsert($name, $product);
            }

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (StorePlatformInterface::RESOURCE_PRODUCT_DELETE === $resource) {
            $productId = $adapter->getDeletedProductId($body);
            if (null !== $productId) {
                $catalogSync->delete($name, $productId);
            }

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $input = $adapter->mapOrder($body);
        if (null === $input) {
            $logger->notice(sprintf('%s order skipped: not paid yet or not an order.', $name), ['order' => $body['id'] ?? null, 'status' => $body['status'] ?? null]);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $payload = $payloadParser->parse($input, $name);
        if (null === $payload) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Updates arrive on every change; only status and refunds matter for known orders.
        $existing = $orderRepository->findOneBy(['orderId' => $payload->orderId, 'orderSource' => $payload->orderSource]);
        if ($existing instanceof EcommerceOrder) {
            $orderManager->applyRefunds($existing, $payload);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $orderTracker->recordOrder($payload, $existingContactLookup->findByEmail($input['email'] ?? null));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Connection test for store connectors. A correctly signed request gets 200 with the plugin's
     * state; everything else gets 204 like every other endpoint, so nothing is revealed without the secret.
     */
    public function pingAction(
        Request $request,
        WebhookSignatureValidator $signatureValidator,
        Config $config,
        LoggerInterface $logger,
    ): Response {
        $rejected = $this->rejectUnlessSigned($request, $config, $signatureValidator, $logger);
        if (null !== $rejected) {
            return $rejected;
        }

        return new JsonResponse([
            'ok'                => true,
            'version'           => self::pluginVersion(),
            'storefront_events' => $config->isStorefrontEventsEnabled(),
        ]);
    }

    private static function pluginVersion(): string
    {
        $config = require \dirname(__DIR__).'/Config/config.php';

        return (string) ($config['version'] ?? '');
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
