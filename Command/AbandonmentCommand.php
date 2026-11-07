<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Command;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\AbandonmentDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run every 5 to 15 minutes from cron.
 */
#[AsCommand(
    name: 'mautic:ecommerce:abandonment',
    description: 'Fire the abandoned cart, checkout and browsing campaign decisions.',
)]
class AbandonmentCommand extends Command
{
    public function __construct(
        private AbandonmentDetector $detector,
        private Config $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isPublished() || !$this->config->isStorefrontEventsEnabled()) {
            $output->writeln('Storefront events are switched off; nothing to do.');

            return Command::SUCCESS;
        }

        foreach ($this->detector->run() as $type => $count) {
            $output->writeln(sprintf('%s: %d abandoned', $type, $count));
        }

        return Command::SUCCESS;
    }
}
