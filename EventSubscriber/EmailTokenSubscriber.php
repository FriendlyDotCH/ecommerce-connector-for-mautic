<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Email tokens for abandonment emails, from the contact's latest storefront events.
 */
final class EmailTokenSubscriber implements EventSubscriberInterface
{
    public const TOKENS = [
        '{ecommerce_cart}'                 => 'mautic.plugin.ecommerce.token.cart',
        '{ecommerce_cart_total}'           => 'mautic.plugin.ecommerce.token.cart_total',
        '{ecommerce_cart_url}'             => 'mautic.plugin.ecommerce.token.cart_url',
        '{ecommerce_viewed_product}'       => 'mautic.plugin.ecommerce.token.viewed_product',
        '{ecommerce_viewed_product_url}'   => 'mautic.plugin.ecommerce.token.viewed_product_url',
        '{ecommerce_viewed_product_image}' => 'mautic.plugin.ecommerce.token.viewed_product_image',
    ];

    public function __construct(
        private EcommerceEventRepository $eventRepository,
        private CurrencyFormatter $currencyFormatter,
        private Config $config,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_ON_BUILD   => ['onEmailBuild', 0],
            EmailEvents::EMAIL_ON_SEND    => ['onEmailGenerate', 0],
            EmailEvents::EMAIL_ON_DISPLAY => ['onEmailGenerate', 0],
        ];
    }

    public function onEmailBuild(EmailBuilderEvent $event): void
    {
        if (!$event->tokensRequested(array_keys(self::TOKENS))) {
            return;
        }

        $tokens = [];
        foreach (self::TOKENS as $token => $label) {
            $tokens[$token] = $this->translator->trans($label);
        }

        $event->addTokens($tokens);
    }

    public function onEmailGenerate(EmailSendEvent $event): void
    {
        $content = $event->getContent().$event->getPlainText().$event->getSubject();
        if (!str_contains($content, '{ecommerce_')) {
            return;
        }

        $lead   = $event->getLead();
        $leadId = (int) (is_array($lead) ? ($lead['id'] ?? 0) : (is_object($lead) ? $lead->getId() : 0));

        $cart   = $leadId > 0 ? $this->eventRepository->findLatestForLead($leadId, [EcommerceEvent::TYPE_ADDED_TO_CART, EcommerceEvent::TYPE_STARTED_CHECKOUT]) : null;
        $viewed = $leadId > 0 ? $this->eventRepository->findLatestForLead($leadId, [EcommerceEvent::TYPE_VIEWED_PRODUCT]) : null;

        $event->addTokens([
            '{ecommerce_cart}'                 => null !== $cart ? $this->renderCart($cart) : '',
            '{ecommerce_cart_total}'           => null !== $cart && null !== $cart->getCartValue() ? $this->money($cart, $cart->getCartValue()) : '',
            '{ecommerce_cart_url}'             => self::escape($cart?->getCartUrl()),
            '{ecommerce_viewed_product}'       => self::escape($viewed?->getProductName() ?? $viewed?->getProductId()),
            '{ecommerce_viewed_product_url}'   => self::escape($viewed?->getProductUrl()),
            '{ecommerce_viewed_product_image}' => self::escape($viewed?->getImageUrl()),
        ]);
    }

    /**
     * A plain, inline-styled table that survives email clients.
     */
    private function renderCart(EcommerceEvent $cart): string
    {
        $rows = '';
        foreach ($cart->getCartItems() ?? [] as $item) {
            $name  = self::escape((string) ($item['item_name'] ?? ''));
            $name  = !empty($item['url']) ? sprintf('<a href="%s" style="color:inherit;">%s</a>', self::escape($item['url']), $name) : $name;
            $image = !empty($item['image_url'])
                ? sprintf('<img src="%s" alt="" width="64" style="display:block;border:0;max-width:64px;">', self::escape($item['image_url']))
                : '';
            $price = null !== ($item['unit_price'] ?? null) ? $this->money($cart, (string) $item['unit_price']) : '';

            $rows .= sprintf(
                '<tr><td style="padding:8px 8px 8px 0;width:64px;">%s</td><td style="padding:8px;">%s</td><td style="padding:8px;text-align:right;white-space:nowrap;">%d &times; %s</td></tr>',
                $image,
                $name,
                (int) ($item['qty'] ?? 1),
                $price
            );
        }

        if (null !== $cart->getCartValue()) {
            $rows .= sprintf(
                '<tr><td></td><td style="padding:8px;font-weight:bold;">%s</td><td style="padding:8px;text-align:right;font-weight:bold;white-space:nowrap;">%s</td></tr>',
                self::escape($this->translator->trans('mautic.plugin.ecommerce.token.total')),
                $this->money($cart, $cart->getCartValue())
            );
        }

        return '' !== $rows ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">'.$rows.'</table>' : '';
    }

    private function money(EcommerceEvent $cart, string $amount): string
    {
        return self::escape($this->currencyFormatter->format($cart->getCurrency() ?? $this->config->getDefaultCurrency(), $amount));
    }

    private static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
