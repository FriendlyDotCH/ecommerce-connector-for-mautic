<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomTemplateEvent;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceContactColumnsDictionary;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the Total revenue column into the `columns` var of the contact list templates
 * (list view, AJAX-refreshed rows, headers and per-row includes).
 */
final class ContactListColumnsSubscriber implements EventSubscriberInterface
{
    private const TEMPLATE_PREFIX = '@MauticLead/Lead/';

    public function __construct(
        private EcommerceContactColumnsDictionary $columnsDictionary,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_TEMPLATE => ['onTemplateRender', 0],
        ];
    }

    public function onTemplateRender(CustomTemplateEvent $event): void
    {
        if (!str_starts_with($event->getTemplate(), self::TEMPLATE_PREFIX)) {
            return;
        }

        $vars = $event->getVars();
        if (!isset($vars['columns']) || !is_array($vars['columns'])) {
            return;
        }

        $vars['columns'] = $this->columnsDictionary->addRevenueColumn($vars['columns']);
        $event->setVars($vars);
    }
}
