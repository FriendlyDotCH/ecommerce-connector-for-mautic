<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\DTO\ProductChange;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProduct;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ProductAlertSubscriber;

/**
 * Turns queued catalog changes into product alert decisions for interested campaign members.
 * A contact gets the same alert for the same product at most once per REPEAT_DAYS.
 */
class ProductAlertDispatcher
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private ProductAlertStore $store,
        private ProductInterest $interest,
        private EcommerceProductRepository $productRepository,
        private CampaignDecisionRunner $decisionRunner,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, array{changes: int, alerted: int}> per change type
     */
    public function run(int $maxChanges = 1000): array
    {
        $stats = [];

        while ($maxChanges > 0 && [] !== ($rows = $this->store->claimPendingChanges(min(self::BATCH_SIZE, $maxChanges)))) {
            $maxChanges -= count($rows);

            foreach ($rows as $row) {
                $type     = $row['change_type'];
                $decision = ProductAlertSubscriber::DECISIONS_BY_CHANGE[$type] ?? null;
                $product  = $this->productRepository->findStoredState($row['store'], $row['external_id']);

                $stats[$type] ??= ['changes' => 0, 'alerted' => 0];
                ++$stats[$type]['changes'];

                if (null === $decision || null === $product) {
                    continue;
                }

                $detectedAt = isset($row['detected_at']) ? new \DateTimeImmutable((string) $row['detected_at'], new \DateTimeZone('UTC')) : null;
                foreach ($this->interest->campaignCandidates($product, $decision, $detectedAt) as $leadId) {
                    if ($this->alertContact($leadId, $decision, $row, $product)) {
                        ++$stats[$type]['alerted'];
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @param array{id: int, store: string, external_id: string, change_type: string, old_value: string|null, new_value: string|null} $row
     */
    private function alertContact(int $leadId, string $decision, array $row, EcommerceProduct $product): bool
    {
        if ($this->store->wasAlertedRecently($leadId, $row['store'], $row['external_id'], $row['change_type'])) {
            return false;
        }

        $lead = $this->entityManager->find(Lead::class, $leadId);
        if (!$lead instanceof Lead) {
            return false;
        }

        $change = new ProductChange(
            $row['id'], $row['store'], $row['external_id'], $row['change_type'], $row['old_value'], $row['new_value'], $product,
            isset($row['detected_at']) ? new \DateTimeImmutable((string) $row['detected_at'], new \DateTimeZone('UTC')) : null
        );

        // Recorded before the run, so the alert email tokens find the product.
        $this->store->recordAlert($leadId, $change, $row['change_type']);
        $this->decisionRunner->run($decision, $lead, $change, null);

        if (!$change->matched) {
            $this->store->deleteAlert($leadId, $change->id);
        }

        $this->entityManager->detach($lead);

        return $change->matched;
    }
}
