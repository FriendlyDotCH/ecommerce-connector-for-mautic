<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class LeadSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EcommerceOrderRepository $orderRepository,
        private Translator $translator,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0],
        ];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $eventTypeName = $this->translator->trans('mautic.plugin.ecommerce.timeline.order');
        $event->addEventType(EcommerceEvents::ORDER_RECORDED, $eventTypeName);

        if (!$event->isApplicable(EcommerceEvents::ORDER_RECORDED)) {
            return;
        }

        $leadId = $event->getLeadId();
        if (!$leadId) {
            return;
        }

        $orders = $this->orderRepository->getOrdersForLeadTimeline($leadId, $event->getQueryOptions());

        if ($event->isEngagementCount()) {
            $event->addToCounter(EcommerceEvents::ORDER_RECORDED, $orders['total']);

            return;
        }

        foreach ($orders['results'] as $order) {
            $orderPk  = (int) $order['id'];
            $currency = strtoupper((string) ($order['order_currency'] ?? ''));
            $amount   = number_format((float) $order['order_total'], 2, '.', '');
            $label    = trim(sprintf('%s %s (%s)', $currency, $amount, $order['order_source']));
            $orderId  = (string) $order['order_id'];

            $event->addEvent([
                'event'           => EcommerceEvents::ORDER_RECORDED,
                'eventId'         => EcommerceEvents::ORDER_RECORDED.'.'.$orderPk,
                'eventLabel'      => $label,
                'eventType'       => $eventTypeName,
                'timestamp'       => $order['date_added'],
                'icon'            => 'ri-shopping-cart-2-line',
                'contactId'       => $leadId,
                'contentTemplate' => '@EcommerceConnector/SubscribedEvents/Timeline/order.html.twig',
                'extra'           => [
                    'orderId'     => $orderId,
                    'modalUrl'    => $this->router->generate('mautic_ecommerce_order_action', [
                        'leadId'   => $leadId,
                        'objectId' => $orderPk,
                    ]),
                    'modalHeader' => $this->translator->trans('mautic.plugin.ecommerce.tab.order_details', [
                        '%order%' => $orderId,
                    ]),
                ],
            ]);
        }
    }
}
