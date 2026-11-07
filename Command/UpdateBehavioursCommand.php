<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Command;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run daily from cron so rolling windows move on even without new orders.
 */
#[AsCommand(
    name: 'mautic:ecommerce:behaviours:update',
    description: 'Recalculate ecommerce behaviour fields for all contacts.',
)]
class UpdateBehavioursCommand extends Command
{
    public function __construct(
        private EcommerceBehaviourRepository $behaviourRepository,
        private BehaviourCalculator $behaviourCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Only recalculate the behaviour with this ID, even if unpublished.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('id');

        if (null !== $id) {
            $behaviour  = $this->behaviourRepository->find((int) $id);
            $behaviours = null !== $behaviour ? [$behaviour] : [];
            if ([] === $behaviours) {
                $output->writeln(sprintf('<error>Behaviour %d not found.</error>', (int) $id));

                return Command::FAILURE;
            }
        } else {
            $behaviours = $this->behaviourRepository->findPublished();
        }

        foreach ($behaviours as $behaviour) {
            $count = $this->behaviourCalculator->recalculate($behaviour);
            $output->writeln(sprintf('%s (%s): %d contacts', $behaviour->getName(), $behaviour->getFieldAlias(), $count));
        }

        return Command::SUCCESS;
    }
}
