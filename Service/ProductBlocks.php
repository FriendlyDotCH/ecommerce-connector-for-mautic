<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;

/**
 * Product blocks for emails: {ecommerce_products source="..." limit="3" category="..."}.
 *
 * Sources: last_purchased, viewed, best_sellers (last 90 days, for sale) and recommended (best
 * sellers in the contact's favourite categories that they have not bought, then overall best
 * sellers), cross_sell (best sellers in the contact's cross-sell category), new_arrivals (newest
 * products for sale). Without a contact (previews, web view of a test send) only best sellers and
 * new arrivals render.
 */
class ProductBlocks
{
    public const SOURCE_LAST_PURCHASED = 'last_purchased';
    public const SOURCE_VIEWED         = 'viewed';
    public const SOURCE_BEST_SELLERS   = 'best_sellers';
    public const SOURCE_RECOMMENDED    = 'recommended';
    public const SOURCE_CROSS_SELL     = 'cross_sell';
    public const SOURCE_NEW_ARRIVALS   = 'new_arrivals';

    public const SOURCES = [self::SOURCE_RECOMMENDED, self::SOURCE_CROSS_SELL, self::SOURCE_BEST_SELLERS, self::SOURCE_NEW_ARRIVALS, self::SOURCE_VIEWED, self::SOURCE_LAST_PURCHASED];

    public const DEFAULT_LIMIT = 3;
    public const MAX_LIMIT     = 12;

    /** Matches the block token as typed, or with the quotes the builders HTML-encode. */
    public const TOKEN_PATTERN = '/\{ecommerce_products((?:\s+[a-z_]+=(?:"[^"{}]*"|&quot;[^{}]*?&quot;|\'[^\'{}]*\'))*)\s*\}/i';

    public function __construct(
        private ProductSales $sales,
        private EcommerceProductRepository $productRepository,
        private CurrencyFormatter $currencyFormatter,
        private Config $config,
    ) {
    }

    /**
     * @return array<string, string> attribute => value
     */
    public static function parseAttributes(string $attributes): array
    {
        preg_match_all('/([a-z_]+)=(?:"([^"]*)"|&quot;(.*?)&quot;|\'([^\']*)\')/i', $attributes, $matches, PREG_SET_ORDER);

        $parsed = [];
        foreach ($matches as $match) {
            $parsed[strtolower($match[1])] = html_entity_decode(($match[2] ?? '').($match[3] ?? '').($match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $parsed;
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return list<EcommerceProduct>
     */
    public function products(array $attributes, ?int $leadId): array
    {
        $source   = in_array($attributes['source'] ?? '', self::SOURCES, true) ? $attributes['source'] : self::SOURCE_RECOMMENDED;
        $limit    = max(1, min(self::MAX_LIMIT, (int) ($attributes['limit'] ?? self::DEFAULT_LIMIT) ?: self::DEFAULT_LIMIT));
        $category = trim($attributes['category'] ?? '');
        $category = '' !== $category ? $category : null;
        $leadId   = null !== $leadId && $leadId > 0 ? $leadId : null;

        $ids = match ($source) {
            self::SOURCE_LAST_PURCHASED => null !== $leadId ? $this->sales->lastPurchased($leadId, $limit) : [],
            self::SOURCE_VIEWED         => null !== $leadId ? $this->sales->recentlyViewed($leadId, $limit) : [],
            self::SOURCE_BEST_SELLERS   => $this->sales->bestSellers($limit, ProductSales::DEFAULT_DAYS, $category),
            self::SOURCE_NEW_ARRIVALS   => $this->sales->newArrivals($limit, $category),
            self::SOURCE_CROSS_SELL     => $this->crossSell($leadId, $limit),
            default                     => $this->recommended($leadId, $limit, $category),
        };

        return $this->load($ids);
    }

    /**
     * Best sellers in the contact's cross-sell category; empty without one.
     *
     * @return list<int>
     */
    private function crossSell(?int $leadId, int $limit): array
    {
        $category = null !== $leadId ? $this->sales->crossSellCategory($leadId) : null;

        return null !== $category
            ? $this->sales->bestSellers($limit, ProductSales::DEFAULT_DAYS, $category, $this->sales->lastPurchased($leadId, 50))
            : [];
    }

    /**
     * @return list<int>
     */
    private function recommended(?int $leadId, int $limit, ?string $category): array
    {
        if (null === $leadId) {
            return $this->sales->bestSellers($limit, ProductSales::DEFAULT_DAYS, $category);
        }

        // Bought before: not recommended again.
        $exclude    = $this->sales->lastPurchased($leadId, 50);
        $categories = null !== $category ? [$category] : $this->sales->favouriteCategories($leadId);
        $ids        = [];

        foreach ([...$categories, null] as $inCategory) {
            if (null === $inCategory && null !== $category) {
                break;
            }

            $more    = $this->sales->bestSellers($limit - count($ids), ProductSales::DEFAULT_DAYS, $inCategory, [...$exclude, ...$ids]);
            $ids     = [...$ids, ...$more];
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<EcommerceProduct> in the order of $ids
     */
    public function load(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->productRepository->findBy(['id' => $ids]) as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?EcommerceProduct => $byId[$id] ?? null, $ids)));
    }

    /**
     * Inline-styled cards, up to three per row; works in Outlook and Gmail.
     *
     * @param list<EcommerceProduct> $products
     * @param array<int, string>     $oldPrices product id => price to cross out (a price drop)
     */
    public function renderHtml(array $products, array $oldPrices = []): string
    {
        if ([] === $products) {
            return '';
        }

        $perRow = min(3, count($products));
        $width  = (int) floor(100 / $perRow);
        $rows   = '';

        foreach (array_chunk($products, $perRow) as $chunk) {
            $cells = '';
            foreach ($chunk as $product) {
                $url   = $product->getUrl();
                $name  = self::escape($product->getName());
                $image = null !== $product->getImageUrl()
                    ? sprintf('<img src="%s" alt="%s" width="160" style="display:block;width:100%%;max-width:160px;height:auto;border:0;margin:0 auto 8px;">', self::escape($product->getImageUrl()), $name)
                    : '';
                $link  = static fn (string $inner): string => null !== $url ? sprintf('<a href="%s" style="color:inherit;text-decoration:none;">%s</a>', self::escape($url), $inner) : $inner;

                $cells .= sprintf(
                    '<td class="ecommerce-product" valign="top" width="%1$d%%" style="width:%1$d%%;padding:8px;text-align:center;vertical-align:top;">%2$s<div style="font-weight:bold;margin-bottom:4px;">%3$s</div><div>%4$s</div></td>',
                    $width,
                    $link($image),
                    $link($name),
                    $this->priceHtml($product, $oldPrices[(int) $product->getId()] ?? null)
                );
            }
            // Keep the cards the same width when the last row is short.
            $cells .= str_repeat(sprintf('<td width="%1$d%%" style="width:%1$d%%;"></td>', $width), $perRow - count($chunk));
            $rows  .= '<tr>'.$cells.'</tr>';
        }

        return '<table role="presentation" class="ecommerce-products" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">'.$rows.'</table>';
    }

    /**
     * @param list<EcommerceProduct> $products
     */
    public function renderText(array $products): string
    {
        $lines = [];
        foreach ($products as $product) {
            $price   = $this->price($product, $product->getPrice());
            $lines[] = '- '.$product->getName().('' !== $price ? ' ('.$price.')' : '').(null !== $product->getUrl() ? ': '.$product->getUrl() : '');
        }

        return implode("\n", $lines);
    }

    private function priceHtml(EcommerceProduct $product, ?string $was = null): string
    {
        $price = self::escape($this->price($product, $product->getPrice()));
        $was ??= $product->getRegularPrice();

        if (null !== $was && null !== $product->getPrice() && (float) $was > (float) $product->getPrice()) {
            return sprintf('<s style="opacity:0.6;">%s</s> %s', self::escape($this->price($product, $was)), $price);
        }

        return $price;
    }

    public function price(EcommerceProduct $product, ?string $amount): string
    {
        return null !== $amount ? $this->currencyFormatter->format($product->getCurrency() ?? $this->config->getDefaultCurrency(), $amount) : '';
    }

    private static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
