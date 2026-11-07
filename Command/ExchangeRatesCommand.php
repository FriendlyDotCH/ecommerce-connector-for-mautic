<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Command;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\BaseCurrencyRebaser;
use MauticPlugin\EcommerceConnectorBundle\Service\EcbRateFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run daily before mautic:ecommerce:recalculate.
 */
#[AsCommand(
    name: 'mautic:ecommerce:exchange-rates',
    description: 'Fetch ECB exchange rates (when enabled) and convert orders to the base currency.',
)]
class ExchangeRatesCommand extends Command
{
    public function __construct(
        private Config $config,
        private EcbRateFetcher $fetcher,
        private BaseCurrencyRebaser $rebaser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('history', null, InputOption::VALUE_NONE, 'Fetch the full ECB history since 1999 instead of the last 90 days.')
            ->addOption('rebase', null, InputOption::VALUE_NONE, 'Convert every order again, for example after changing the base currency or rates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->config->isEcbExchangeRatesEnabled()) {
            try {
                $output->writeln(sprintf('ECB rates stored: %d', $this->fetcher->fetch((bool) $input->getOption('history'))));
            } catch (\Throwable $exception) {
                // Keep converting with the rates already stored.
                $output->writeln('<error>ECB rates could not be fetched: '.$exception->getMessage().'</error>');
            }
        }

        $result = $this->rebaser->rebase((bool) $input->getOption('rebase'));
        $output->writeln(sprintf(
            'Base currency %s: %d orders converted, %d without a rate.',
            $this->config->getBaseCurrency(),
            $result['converted'],
            $result['without_rate']
        ));

        return Command::SUCCESS;
    }
}
