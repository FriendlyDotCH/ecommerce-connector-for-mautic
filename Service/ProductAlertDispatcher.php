<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use MauticPlugin\EcommerceConnectorBundle\DTO\ProductChange;
use MauticPlugin\EcommerceConnectorBundle\DTO\ProductChangeSet;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\ProductAlertSubscriber;

/**
 * Turns queued catalog changes into product alert decisions for interested campaign members.
 * A contact gets the same alert for the same product at most once per REPEAT_DAYS, all products
 * of one run in one decision (one email), and at most one alert per cap period.
 */
class ProductAlertDispatcher
{
    public function __construct(
        private ProductAlertStore $store,
        private ProductInterest $interest,
        private EcommerceProductRepository $productRepository,
        private CampaignDecisionRunner $decisionRunner,
        private EntityManagerInterface $entityManager,
        private Config $config,
    ) {
    }

    /**
     * Claims the waiting changes (once the oldest is past the collection window), groups them per
     * contact and alert type, and runs each alert decision once per contact with all its changes.
     * A contact who got a product alert within the cap is skipped (back in stock goes first).
     *
     * @return array<string, array{changes: int, alerted: int}> per change type; alerted counts products
     */
    public function run(int $maxChanges = 1000): array
    {
        $rows = $this->store->claimPendingChanges(max(1, $maxChanges), $this->config->getAlertCollectMinutes());
        if ([] === $rows) {
            return [];
        }

        $stats = [];
        /** @var array<string, array<int, list<ProductChange>>> $byDecision decision => lead => changes */
        $byDecision = array_fill_keys(ProductAlertSubscriber::DECISIONS_BY_CHANGE, []);

        foreach ($rows as $row) {
            $type     = $row['change_type'];
            $decision = ProductAlertSubscriber::DECISIONS_BY_CHANGE[$type] ?? null;
            $product  = $this->productRepository->findStoredState($row['store'], $row['external_id']);

            $stats[$type] ??= ['changes' => 0, 'alerted' => 0];
            ++$stats[$type]['changes'];

            if (null === $decision || null === $product) {
                continue;
            }

            $change = new ProductChange(
                $row['id'], $row['store'], $row['external_id'], $type, $row['old_value'], $row['new_value'], $product,
                new \DateTimeImmutable((string) $row['detected_at'], new \DateTimeZone('UTC'))
            );
            foreach ($this->interest->campaignCandidates($product, $decision, $change->detectedAt) as $leadId) {
                if (!$this->store->wasAlertedRecently($leadId, $change->store, $change->externalId, $type)) {
                    $byDecision[$decision][$leadId][] = $change;
                }
            }
        }

        $capHours = $this->config->getAlertCapHours();
        foreach ($byDecision as $decision => $leads) {
            foreach ($leads as $leadId => $changes) {
                if ($capHours > 0 && $this->store->wasAlertedSince($leadId, new \DateTimeImmutable(sprintf('-%d hours', $capHours)))) {
                    continue;
                }

                // Fresh objects per contact: "matched" is per contact.
                $set = new ProductChangeSet(
                    $changes[0]->type,
                    array_map(static fn (ProductChange $c): ProductChange => new ProductChange($c->id, $c->store, $c->externalId, $c->type, $c->oldValue, $c->newValue, $c->product, $c->detectedAt), $changes),
                    ProductAlertStore::now()
                );
                $stats[$set->type]['alerted'] += $this->alertContact($leadId, $decision, $set);
            }
        }

        return $stats;
    }

    /**
     * @return int how many of the products the contact was alerted about
     */
    private function alertContact(int $leadId, string $decision, ProductChangeSet $set): int
    {
        $lead = $this->entityManager->find(Lead::class, $leadId);
        if (!$lead instanceof Lead) {
            return 0;
        }

        // The decision records the matched alerts before the campaign actions (the email) run.
        $this->decisionRunner->run($decision, $lead, $set, null);
        $this->entityManager->detach($lead);

        return count(array_filter($set->changes, static fn (ProductChange $change): bool => $change->matched));
    }
}
