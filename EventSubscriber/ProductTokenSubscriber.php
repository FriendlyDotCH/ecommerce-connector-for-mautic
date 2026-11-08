<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductAlertStore;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductBlocks;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Product content in emails: the product of the contact's latest product alert, and product
 * blocks ({ecommerce_products source="..." limit="..." category="..."}).
 *
 * Blocks are written into the content itself, before Mautic converts links to tracked links
 * (priority -9999), so clicks on them are tracked and attributed like any other email link.
 */
final class ProductTokenSubscriber implements EventSubscriberInterface
{
    public const ALERT_TOKENS = [
        '{ecommerce_alert_product}'           => 'mautic.plugin.ecommerce.token.alert_product',
        '{ecommerce_alert_product_url}'       => 'mautic.plugin.ecommerce.token.alert_product_url',
        '{ecommerce_alert_product_image}'     => 'mautic.plugin.ecommerce.token.alert_product_image',
        '{ecommerce_alert_product_price}'     => 'mautic.plugin.ecommerce.token.alert_product_price',
        '{ecommerce_alert_product_old_price}' => 'mautic.plugin.ecommerce.token.alert_product_old_price',
        '{ecommerce_alert_product_stock}'     => 'mautic.plugin.ecommerce.token.alert_product_stock',
    ];

    public function __construct(
        private ProductBlocks $blocks,
        private ProductAlertStore $alertStore,
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
        $tokens = [];
        foreach (ProductBlocks::SOURCES as $source) {
            $tokens[sprintf('{ecommerce_products source="%s" limit="%d"}', $source, ProductBlocks::DEFAULT_LIMIT)] = $this->translator->trans('mautic.plugin.ecommerce.token.products.'.$source);
        }
        foreach (self::ALERT_TOKENS as $token => $label) {
            $tokens[$token] = $this->translator->trans($label);
        }

        if ($event->tokensRequested(array_keys($tokens))) {
            $event->addTokens($tokens);
        }
    }

    public function onEmailGenerate(EmailSendEvent $event): void
    {
        $html  = $event->getContent();
        $text  = $event->getPlainText();
        $all   = $html.$text.$event->getSubject();
        if (!str_contains($all, '{ecommerce_products') && !str_contains($all, '{ecommerce_alert_product')) {
            return;
        }

        $lead   = $event->getLead();
        $leadId = (int) (is_array($lead) ? ($lead['id'] ?? 0) : (is_object($lead) ? $lead->getId() : 0));

        if (str_contains($html, '{ecommerce_products')) {
            $event->setContent($this->replaceBlocks($html, $leadId, true));
        }
        if (str_contains($text, '{ecommerce_products')) {
            $event->setPlainText($this->replaceBlocks($text, $leadId, false));
        }

        if (str_contains($all, '{ecommerce_alert_product')) {
            $event->addTokens($this->alertTokens($leadId));
        }
    }

    private function replaceBlocks(string $content, int $leadId, bool $html): string
    {
        $rendered = [];

        return (string) preg_replace_callback(ProductBlocks::TOKEN_PATTERN, function (array $match) use ($leadId, $html, &$rendered): string {
            // The same block twice in one email renders once.
            if (!isset($rendered[$match[0]])) {
                $products            = $this->blocks->products(ProductBlocks::parseAttributes($match[1]), $leadId ?: null);
                $rendered[$match[0]] = $html ? $this->blocks->renderHtml($products) : $this->blocks->renderText($products);
            }

            return $rendered[$match[0]];
        }, $content);
    }

    /**
     * @return array<string, string>
     */
    private function alertTokens(int $leadId): array
    {
        $alert = $leadId > 0 ? $this->alertStore->latestAlertWithProduct($leadId) : null;
        if (null === $alert) {
            return array_fill_keys(array_keys(self::ALERT_TOKENS), '');
        }

        $currency = is_string($alert['currency']) && '' !== $alert['currency'] ? $alert['currency'] : $this->config->getDefaultCurrency();
        $money    = fn (mixed $amount): string => is_numeric($amount) ? self::escape($this->currencyFormatter->format($currency, (string) $amount)) : '';
        // For a price drop the alert holds the old and new price; otherwise the old price is the regular one.
        $isPriceDrop = 'price_drop' === $alert['alert_type'];
        $oldPrice    = $isPriceDrop ? $alert['old_value'] : (null !== $alert['regular_price'] && (float) $alert['regular_price'] > (float) $alert['price'] ? $alert['regular_price'] : null);

        return [
            '{ecommerce_alert_product}'           => self::escape($alert['name']),
            '{ecommerce_alert_product_url}'       => self::escape($alert['url']),
            '{ecommerce_alert_product_image}'     => self::escape($alert['image_url']),
            '{ecommerce_alert_product_price}'     => $money($alert['price']),
            '{ecommerce_alert_product_old_price}' => $money($oldPrice),
            '{ecommerce_alert_product_stock}'     => null !== $alert['stock_quantity'] ? (string) (int) $alert['stock_quantity'] : '',
        ];
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
