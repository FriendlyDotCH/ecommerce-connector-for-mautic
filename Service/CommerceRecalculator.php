<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;

/**
 * The one entry point for computed contact values: timing, lifecycle, cross-sell, interest, lapsed
 * category and behaviours, for one
 * contact after an order change, or for everyone from the daily job.
 */
class CommerceRecalculator
{
    public function __construct(
        private CommerceMetricsCalculator $metricsCalculator,
        private LifecycleCalculator $lifecycleCalculator,
        private BehaviourCalculator $behaviourCalculator,
        private EcommerceBehaviourRepository $behaviourRepository,
        private CrossSellCalculator $crossSellCalculator,
        private CrossSellSegments $crossSellSegments,
        private InterestCalculator $interestCalculator,
        private CategoryLapseCalculator $categoryLapseCalculator,
    ) {
    }

    public function recalculateForLead(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        $this->metricsCalculator->write([$leadId]);
        $this->lifecycleCalculator->write([$leadId]);
        $this->crossSellCalculator->write([$leadId]);
        $this->interestCalculator->write([$leadId]);
        $this->categoryLapseCalculator->write([$leadId]);
        $this->behaviourCalculator->recalculateForLead($leadId);
    }

    /**
     * After a storefront event: only interest depends on those.
     */
    public function recalculateInterestForLead(int $leadId): void
    {
        if ($leadId > 0) {
            $this->interestCalculator->write([$leadId]);
        }
    }

    /**
     * @return array<string, int> what was recalculated => number of contacts
     */
    public function recalculateAll(?\DateTimeInterface $now = null): array
    {
        $result = [
            'commerce fields' => $this->metricsCalculator->recalculateAll($now),
            'lifecycle'       => $this->lifecycleCalculator->recalculateAll($now),
            'cross-sell'      => $this->crossSellCalculator->recalculateAll(),
            'interest'        => $this->interestCalculator->recalculateAll($now),
            'lapsed category' => $this->categoryLapseCalculator->recalculateAll($now),
        ];

        $segments = $this->crossSellSegments->sync();
        if ($segments > 0) {
            $result['cross-sell segments created'] = $segments;
        }

        foreach ($this->behaviourRepository->findPublished() as $behaviour) {
            $result[$behaviour->getName().' ('.$behaviour->getFieldAlias().')'] = $this->behaviourCalculator->recalculate($behaviour, $now);
        }

        return $result;
    }
}
