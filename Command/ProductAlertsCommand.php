<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Command;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\ProductAlertDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run every 5 to 15 minutes from cron.
 */
#[AsCommand(
    name: 'mautic:ecommerce:product-alerts',
    description: 'Fire the back in stock, price drop and low inventory campaign decisions for catalog changes.',
)]
class ProductAlertsCommand extends Command
{
    public function __construct(
        private ProductAlertDispatcher $dispatcher,
        private Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most catalog changes to process in this run.', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isPublished()) {
            $output->writeln('The Ecommerce Connector is not published; nothing to do.');

            return Command::SUCCESS;
        }

        $stats = $this->dispatcher->run(max(1, (int) $input->getOption('limit')));
        if ([] === $stats) {
            $output->writeln('No catalog changes waiting.');
        }

        foreach ($stats as $type => $counts) {
            $output->writeln(sprintf('%s: %d changes, %d contacts alerted', $type, $counts['changes'], $counts['alerted']));
        }

        return Command::SUCCESS;
    }
}
