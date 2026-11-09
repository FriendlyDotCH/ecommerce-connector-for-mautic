<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use MauticPlugin\EcommerceConnectorBundle\Service\OpportunityFinder;
use MauticPlugin\EcommerceConnectorBundle\Service\OpportunitySegments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ready-made audiences from what customers bought: who to contact now, how many, and what one
 * order from each is typically worth. Each can become a segment.
 */
class OpportunityController extends CommonController
{
    /** Same as the catalog: anyone who can see contacts can see what they buy. */
    public const PERMISSION = 'lead:leads:viewown';

    public const SEGMENT_PERMISSION = 'lead:lists:create';

    private const ACTIVE_LINK = '#mautic_ecommerce_opportunity_index';

    private const MAUTIC_CONTENT = 'ecommerceopportunity';

    public function indexAction(OpportunityFinder $finder, OpportunitySegments $segments, CurrencyFormatter $formatter, Config $config): Response
    {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $opportunities = $finder->findAll();
        $currency      = $config->getBaseCurrency();
        $money         = static fn (float $amount): string => $formatter->format($currency, $amount);

        $rows = array_map(static fn (array $o): array => $o + [
            'typical_formatted'   => $money($o['typical_order']),
            'potential_formatted' => $money($o['potential']),
        ], $opportunities);

        return $this->delegateView([
            'viewParameters'  => [
                'items'         => $rows,
                'segments'      => $segments->findExisting($opportunities),
                'canSegment'    => $this->security->isGranted(self::SEGMENT_PERMISSION),
                'totalContacts' => array_sum(array_column($opportunities, 'contacts')),
                'totalPotential' => $money((float) array_sum(array_column($opportunities, 'potential'))),
                'currency'      => $currency,
            ],
            'contentTemplate' => '@EcommerceConnector/Opportunity/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => self::ACTIVE_LINK,
                'route'         => $this->generateUrl('mautic_ecommerce_opportunity_index'),
                'mauticContent' => self::MAUTIC_CONTENT,
            ],
        ]);
    }

    public function segmentAction(Request $request, OpportunityFinder $finder, OpportunitySegments $segments): Response
    {
        // Ajax only: Mautic checks the CSRF header on ajax posts.
        if (!$request->isXmlHttpRequest() || !$this->security->isGranted(self::SEGMENT_PERMISSION)) {
            return $this->accessDenied();
        }

        $key         = (string) $request->query->get('key', '');
        $opportunity = '' !== $key ? $finder->find($key) : null;
        if (null === $opportunity) {
            return $this->redirectToList([[
                'type' => 'error',
                'msg'  => 'mautic.plugin.ecommerce.opportunity.gone',
            ]]);
        }

        $title  = $this->translator->trans('mautic.plugin.ecommerce.opportunity.type.'.$opportunity['type'].'.title', $opportunity['params']);
        $result = $segments->create(
            $opportunity,
            $this->translator->trans('mautic.plugin.ecommerce.opportunity.segment_name', ['%title%' => $title]),
            $this->translator->trans('mautic.plugin.ecommerce.opportunity.type.'.$opportunity['type'].'.description', $opportunity['params'])
        );

        return $this->redirectToList([[
            'type'    => 'notice',
            'msg'     => $result['created'] ? 'mautic.plugin.ecommerce.opportunity.segment_created' : 'mautic.plugin.ecommerce.opportunity.segment_exists',
            'msgVars' => ['%name%' => $result['segment']->getName()],
        ]]);
    }

    /**
     * @param list<array<string, mixed>> $flashes
     */
    private function redirectToList(array $flashes): Response
    {
        return $this->postActionRedirect([
            'returnUrl'       => $this->generateUrl('mautic_ecommerce_opportunity_index'),
            'contentTemplate' => static::class.'::indexAction',
            'passthroughVars' => [
                'activeLink'    => self::ACTIVE_LINK,
                'mauticContent' => self::MAUTIC_CONTENT,
            ],
            'flashes'         => $flashes,
        ]);
    }
}
