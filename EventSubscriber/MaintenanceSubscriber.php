<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\MaintenanceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deletes storefront events past the retention period during mautic:maintenance:cleanup.
 * Uses the plugin's own retention setting, not the command's --days-old.
 */
final class MaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EcommerceEventRepository $eventRepository,
        private Config $config,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::MAINTENANCE_CLEANUP_DATA => ['onDataCleanup', 0],
        ];
    }

    public function onDataCleanup(MaintenanceEvent $event): void
    {
        $before = new \DateTimeImmutable(sprintf('-%d days', $this->config->getEventRetentionDays()));

        // The command prints labels as given; core subscribers translate them first too.
        $event->setStat(
            $this->translator->trans('mautic.plugin.ecommerce.maintenance.events'),
            $this->eventRepository->deleteOlderThan($before, $event->isDryRun())
        );
    }
}
