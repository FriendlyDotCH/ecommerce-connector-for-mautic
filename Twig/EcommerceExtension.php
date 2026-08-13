<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Twig;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class EcommerceExtension extends AbstractExtension
{
    public function __construct(
        private Config $config,
        private CurrencyFormatter $currencyFormatter,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ecommerce_money', [$this, 'formatMoney']),
        ];
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return $this->currencyFormatter->format(
            $this->config->getDefaultCurrency(),
            null === $amount || '' === $amount ? 0 : $amount
        );
    }
}
