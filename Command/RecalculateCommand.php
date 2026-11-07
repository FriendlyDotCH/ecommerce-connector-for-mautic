<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Command;

use MauticPlugin\EcommerceConnectorBundle\Service\CommerceRecalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The daily job: timing metrics and all published behaviours for every contact with orders.
 */
#[AsCommand(
    name: 'mautic:ecommerce:recalculate',
    description: 'Recalculate computed ecommerce contact fields: timing metrics and behaviours.',
)]
class RecalculateCommand extends Command
{
    public function __construct(
        private CommerceRecalculator $commerceRecalculator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->commerceRecalculator->recalculateAll() as $label => $count) {
            $output->writeln(sprintf('%s: %d contacts', $label, $count));
        }

        return Command::SUCCESS;
    }
}
