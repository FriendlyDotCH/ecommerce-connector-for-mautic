<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\EventListener\DashboardSubscriber as MainDashboardSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceAnalytics;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DashboardSubscriber extends MainDashboardSubscriber
{
    public const REVENUE_IN_TIME     = 'ecommerce.revenue.in.time';
    public const KEY_FIGURES         = 'ecommerce.key.figures';
    public const REVENUE_BY_CAMPAIGN = 'ecommerce.revenue.by.campaign';
    public const REVENUE_BY_EMAIL    = 'ecommerce.revenue.by.email';

    protected $bundle = 'ecommerce';

    protected $types = [
        self::REVENUE_IN_TIME     => [],
        self::KEY_FIGURES         => [],
        self::REVENUE_BY_CAMPAIGN => [],
        self::REVENUE_BY_EMAIL    => [],
    ];

    // Revenue belongs to contacts.
    protected $permissions = [
        'lead:leads:viewother',
    ];

    public function __construct(
        private EcommerceAnalytics $analytics,
        private CurrencyFormatter $currencyFormatter,
        private Config $config,
        private RouterInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function onWidgetDetailGenerate(WidgetDetailEvent $event): void
    {
        if (!isset($this->types[$event->getType()])) {
            return;
        }

        $this->checkPermissions($event);
        if ($event->isPropagationStopped()) {
            return;
        }

        $widget = $event->getWidget();
        $params = $widget->getParams();

        if (self::REVENUE_IN_TIME === $event->getType()) {
            if (!$event->isCached()) {
                $event->setTemplateData([
                    'chartType'   => 'line',
                    'chartHeight' => $widget->getHeight() - 80,
                    'chartData'   => $this->analytics->getRevenueLineChartData($params['timeUnit'] ?? null, $params['dateFrom'], $params['dateTo'], $params['dateFormat'] ?? null),
                ]);
            }

            $event->setTemplate('@MauticCore/Helper/chart.html.twig');
            $event->stopPropagation();

            return;
        }

        if (!$event->isCached()) {
            $event->setTemplateData(match ($event->getType()) {
                self::KEY_FIGURES         => $this->keyFigures($params),
                self::REVENUE_BY_CAMPAIGN => $this->rankedTable($this->analytics->getRevenueByCampaign($params['dateFrom'], $params['dateTo'], $this->limit($widget->getHeight(), $params)), 'mautic_campaign_action', 'mautic.campaign.campaign'),
                default                   => $this->rankedTable($this->analytics->getRevenueByEmail($params['dateFrom'], $params['dateTo'], $this->limit($widget->getHeight(), $params)), 'mautic_email_action', 'mautic.email.email'),
            });
        }

        $event->setTemplate('@MauticCore/Helper/table.html.twig');
        $event->stopPropagation();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function keyFigures(array $params): array
    {
        $figures  = $this->analytics->getKeyFigures($params['dateFrom'], $params['dateTo']);
        $currency = $this->config->getDefaultCurrency();
        $money    = fn (float $amount): string => $this->currencyFormatter->format($currency, $amount);

        $rows = [
            ['mautic.plugin.ecommerce.widget.revenue', $money($figures['revenue'])],
            ['mautic.plugin.ecommerce.widget.orders', (string) $figures['orders']],
            ['mautic.plugin.ecommerce.widget.customers', (string) $figures['customers']],
            ['mautic.plugin.ecommerce.widget.average_order_value', $money($figures['average_order_value'])],
            ['mautic.plugin.ecommerce.widget.repeat_rate', $figures['repeat_rate'].' %'],
            ['mautic.plugin.ecommerce.widget.average_lifetime_value', $money($figures['average_lifetime_value'])],
            ['mautic.plugin.ecommerce.widget.attributed_revenue', $money($figures['attributed_revenue'])],
        ];

        return [
            'headItems' => ['mautic.plugin.ecommerce.widget.figure', 'mautic.plugin.ecommerce.widget.value'],
            'bodyItems' => array_map(fn (array $row): array => [
                ['value' => $this->translator->trans($row[0])],
                ['value' => $row[1]],
            ], $rows),
            'raw'       => $figures,
        ];
    }

    /**
     * @param list<array{id: int, name: string, orders: int, revenue: float}> $rows
     *
     * @return array<string, mixed>
     */
    private function rankedTable(array $rows, string $route, string $nameLabel): array
    {
        $currency = $this->config->getDefaultCurrency();

        return [
            'headItems' => [$nameLabel, 'mautic.plugin.ecommerce.widget.orders', 'mautic.plugin.ecommerce.widget.revenue'],
            'bodyItems' => array_map(fn (array $row): array => [
                [
                    'value' => $row['name'],
                    'type'  => 'link',
                    'link'  => $this->router->generate($route, ['objectAction' => 'view', 'objectId' => $row['id']]),
                ],
                ['value' => $row['orders']],
                ['value' => $this->currencyFormatter->format($currency, $row['revenue'])],
            ], $rows),
            'raw'       => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function limit(int $height, array $params): int
    {
        return !empty($params['limit']) ? (int) $params['limit'] : max(1, (int) round((($height - 80) / 35) - 1));
    }
}
