<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\EventSubscriber;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\EcommerceConnectorBundle\Exception\CouponException;
use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponIssuer;
use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponStore;
use MauticPlugin\EcommerceConnectorBundle\EcommerceEvents;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\CouponActionType;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\CurrencyFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Issue coupon" campaign action, "Coupon used" condition and the coupon email tokens.
 */
final class CouponSubscriber implements EventSubscriberInterface
{
    public const ACTION_ISSUE_COUPON      = 'ecommerce.issue_coupon';
    public const CONDITION_COUPON_REDEEMED = 'ecommerce.coupon_redeemed';

    /**
     * Mautic retries a failed action until it passes, notifies after repeated failures and
     * unpublishes a campaign most of whose contacts fail. A shop that is down is asked again
     * soon; a refusal (setting off, invalid values) needs someone to fix it, so less often.
     */
    public const RETRY_INTERVAL         = 'PT15M';
    public const REFUSED_RETRY_INTERVAL = 'PT1H';

    public const TOKENS = [
        '{ecommerce_coupon}'        => 'mautic.plugin.ecommerce.token.coupon',
        '{ecommerce_coupon_value}'  => 'mautic.plugin.ecommerce.token.coupon_value',
        '{ecommerce_coupon_expiry}' => 'mautic.plugin.ecommerce.token.coupon_expiry',
    ];

    public function __construct(
        private CouponIssuer $issuer,
        private CouponStore $store,
        private CurrencyFormatter $currencyFormatter,
        private Config $config,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD            => ['onCampaignBuild', 0],
            EcommerceEvents::ON_CAMPAIGN_BATCH_ACTION    => ['onIssueCoupon', 0],
            EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION => ['onCouponRedeemedCondition', 0],
            EmailEvents::EMAIL_ON_BUILD                  => ['onEmailBuild', 0],
            EmailEvents::EMAIL_ON_SEND                   => ['onEmailGenerate', 0],
            EmailEvents::EMAIL_ON_DISPLAY                => ['onEmailGenerate', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(self::ACTION_ISSUE_COUPON, [
            'label'          => 'mautic.plugin.ecommerce.campaign.issue_coupon',
            'description'    => 'mautic.plugin.ecommerce.campaign.issue_coupon.descr',
            'formType'       => CouponActionType::class,
            'batchEventName' => EcommerceEvents::ON_CAMPAIGN_BATCH_ACTION,
        ]);

        $event->addCondition(self::CONDITION_COUPON_REDEEMED, [
            'label'       => 'mautic.plugin.ecommerce.campaign.coupon_redeemed',
            'description' => 'mautic.plugin.ecommerce.campaign.coupon_redeemed.descr',
            'eventName'   => EcommerceEvents::ON_CAMPAIGN_TRIGGER_CONDITION,
        ]);
    }

    public function onIssueCoupon(PendingEvent $pending): void
    {
        if (!$pending->checkContext(self::ACTION_ISSUE_COUPON)) {
            return;
        }

        $event    = $pending->getEvent();
        $settings = (array) $event->getProperties();
        $retry    = new \DateInterval(self::RETRY_INTERVAL);
        $refused  = new \DateInterval(self::REFUSED_RETRY_INTERVAL);

        foreach ($pending->getPending() as $log) {
            $lead  = $log->getLead();
            $email = trim((string) $lead->getEmail());

            if ('' === $email) {
                $pending->passWithError($log, $this->translator->trans('mautic.plugin.ecommerce.coupon.no_email'));
                continue;
            }

            try {
                $this->issuer->issue((int) $lead->getId(), $email, $event->getCampaign()->getId(), $event->getId(), $settings);
                $pending->pass($log);
            } catch (CouponException $exception) {
                $this->logger->warning('Ecommerce coupon not issued: '.$exception->getMessage(), [
                    'contact_id'        => $lead->getId(),
                    'campaign_event_id' => $event->getId(),
                ]);

                // Never pass without a coupon: the email after this action would go out without a code.
                $pending->fail($log, $exception->getMessage(), $exception->retryable ? $retry : $refused);
            }
        }
    }

    public function onCouponRedeemedCondition(CampaignExecutionEvent $event): ?CampaignExecutionEvent
    {
        if (!$event->checkContext(self::CONDITION_COUPON_REDEEMED)) {
            return null;
        }

        $campaignId = (int) ($event->getEvent()['campaign']['id'] ?? 0);

        return $event->setResult($campaignId > 0 && $this->store->hasRedeemedInCampaign((int) $event->getLead()->getId(), $campaignId));
    }

    public function onEmailBuild(EmailBuilderEvent $event): void
    {
        if (!$event->tokensRequested(array_keys(self::TOKENS))) {
            return;
        }

        $event->addTokens(array_map(fn (string $label): string => $this->translator->trans($label), self::TOKENS));
    }

    public function onEmailGenerate(EmailSendEvent $event): void
    {
        if (!str_contains($event->getContent().$event->getPlainText().$event->getSubject(), '{ecommerce_coupon')) {
            return;
        }

        $lead   = $event->getLead();
        $leadId = (int) (is_array($lead) ? ($lead['id'] ?? 0) : (is_object($lead) ? $lead->getId() : 0));
        $coupon = $leadId > 0 ? $this->store->findLatestOpen($leadId) : null;

        if (null === $coupon) {
            $event->addTokens(array_fill_keys(array_keys(self::TOKENS), ''));

            return;
        }

        $value = CouponIssuer::TYPE_PERCENT === $coupon['discount_type']
            ? rtrim(rtrim(number_format((float) $coupon['amount'], 2, '.', ''), '0'), '.').' %'
            : $this->currencyFormatter->format((string) ($coupon['currency'] ?? $this->config->getDefaultCurrency()), (string) $coupon['amount']);

        // The last valid day, as sent to the shop, in the money locale's date format.
        $expiry = '';
        if (null !== $coupon['expires_at']) {
            $date      = new \DateTimeImmutable((string) $coupon['expires_at'], new \DateTimeZone('UTC'));
            $formatter = new \IntlDateFormatter($this->config->getMoneyLocale() ?: 'en_US', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
            $expiry    = htmlspecialchars((string) ($formatter->format($date) ?: $date->format('Y-m-d')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $event->addTokens([
            '{ecommerce_coupon}'        => htmlspecialchars((string) $coupon['code'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{ecommerce_coupon_value}'  => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{ecommerce_coupon_expiry}' => $expiry,
        ]);
    }
}
