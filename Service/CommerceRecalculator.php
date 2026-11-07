<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;

/**
 * The one entry point for computed contact values: timing, lifecycle and behaviours, for one
 * contact after an order change, or for everyone from the daily job.
 */
class CommerceRecalculator
{
    public function __construct(
        private CommerceMetricsCalculator $metricsCalculator,
        private LifecycleCalculator $lifecycleCalculator,
        private BehaviourCalculator $behaviourCalculator,
        private EcommerceBehaviourRepository $behaviourRepository,
    ) {
    }

    public function recalculateForLead(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        $this->metricsCalculator->write([$leadId]);
        $this->lifecycleCalculator->write([$leadId]);
        $this->behaviourCalculator->recalculateForLead($leadId);
    }

    /**
     * @return array<string, int> what was recalculated => number of contacts
     */
    public function recalculateAll(?\DateTimeInterface $now = null): array
    {
        $result = [
            'timing'    => $this->metricsCalculator->recalculateAll($now),
            'lifecycle' => $this->lifecycleCalculator->recalculateAll($now),
        ];

        foreach ($this->behaviourRepository->findPublished() as $behaviour) {
            $result[$behaviour->getName().' ('.$behaviour->getFieldAlias().')'] = $this->behaviourCalculator->recalculate($behaviour, $now);
        }

        return $result;
    }
}
