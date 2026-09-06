<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Twig;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class EcommerceExtension extends AbstractExtension
{
    public function __construct(
        private Config $config,
        private CurrencyFormatter $currencyFormatter,
        private PrivacyUpgradeNotice $privacyUpgradeNotice,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ecommerce_money', [$this, 'formatMoney']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ecommerce_privacy_upgrade_notice_pending', [$this, 'isPrivacyUpgradeNoticePending']),
        ];
    }

    public function isPrivacyUpgradeNoticePending(): bool
    {
        return $this->privacyUpgradeNotice->isPending();
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return $this->currencyFormatter->format(
            $this->config->getDefaultCurrency(),
            null === $amount || '' === $amount ? 0 : $amount
        );
    }
}
