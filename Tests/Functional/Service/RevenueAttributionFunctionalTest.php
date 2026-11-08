<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CoreBundle\Helper\ClickthroughHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\DashboardBundle\Entity\Widget;
use Mautic\DashboardBundle\Factory\WidgetDetailEventFactory;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\DashboardSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceAnalytics;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

class RevenueAttributionFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Campaign $campaign;

    private Email $email;

    private Lead $lead;

    private Stat $stat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);

        $this->campaign = new Campaign();
        $this->campaign->setName('Win-back');
        $this->em->persist($this->campaign);

        $event = new Event();
        $event->setName('Send win-back email');
        $event->setType('email.send');
        $event->setEventType('action');
        $event->setCampaign($this->campaign);
        $this->em->persist($event);

        $this->email = new Email();
        $this->email->setName('Win-back offer');
        $this->email->setSubject('We miss you');
        $this->em->persist($this->email);

        $this->lead = new Lead();
        $this->lead->setEmail('attributed@example.com');
        $this->em->persist($this->lead);
        $this->em->flush();

        $this->stat = new Stat();
        $this->stat->setEmail($this->email);
        $this->stat->setLead($this->lead);
        $this->stat->setEmailAddress('attributed@example.com');
        $this->stat->setDateSent(new \DateTime('-2 days'));
        $this->stat->setSource('campaign.event');
        $this->stat->setSourceId($event->getId());
        $this->stat->setTrackingHash('hash-winback-1');
        $this->em->persist($this->stat);
        $this->em->flush();
    }

    public function testWebhookOrderFromAnEmailLinkIsAttributedByTheTrackingHash(): void
    {
        $this->configureIntegration();
        $other = new Email();
        $other->setName('Unrelated');
        $other->setSubject('Unrelated');
        $this->em->persist($other);
        $this->em->flush();

        // The "email" in the link names another email; the tracking hash decides.
        $this->postSignedJsonOrder([
            'order_id'     => 'ct-1',
            'order_total'  => '60',
            'order_source' => 'web',
            'email'        => 'attributed@example.com',
            'ct'           => ClickthroughHelper::encodeArrayForUrl(['source' => ['email', $other->getId()], 'email' => $other->getId(), 'stat' => 'hash-winback-1']),
        ]);

        $row = $this->connection->fetchAssociative('SELECT email_id, campaign_id FROM '.MAUTIC_TABLE_PREFIX."ecommerce_orders WHERE order_id = 'ct-1'");
        Assert::assertSame($this->email->getId(), (int) $row['email_id']);
        Assert::assertSame($this->campaign->getId(), (int) $row['campaign_id']);
    }

    public function testMalformedLinkParameterDoesNotLoseTheOrder(): void
    {
        $this->configureIntegration();

        $this->postSignedJsonOrder([
            'order_id'     => 'ct-bad',
            'order_total'  => '60',
            'order_source' => 'web',
            'email'        => 'attributed@example.com',
            'ct'           => base64_encode('not a serialized array'),
        ]);

        $row = $this->connection->fetchAssociative('SELECT email_id, campaign_id FROM '.MAUTIC_TABLE_PREFIX."ecommerce_orders WHERE order_id = 'ct-bad'");
        Assert::assertIsArray($row);
        Assert::assertNull($row['campaign_id']);
    }

    public function testOrdersAreAttributedToTheCampaignAndReported(): void
    {
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);

        // Via the click token's tracking hash.
        $tracker->recordOrder(new OrderPayload('att-1', '120', 'web', 'EUR', $this->email->getId(), ['stat' => 'hash-winback-1'], null, [], new \DateTimeImmutable('-1 day')), $this->lead);
        // Via the contact's latest send of the email.
        $tracker->recordOrder(new OrderPayload('att-2', '80', 'web', 'EUR', $this->email->getId(), null, null, [], new \DateTimeImmutable('-1 hour')), $this->lead);
        // Not attributed, and a cancelled one that must not count.
        $tracker->recordOrder(new OrderPayload('att-3', '50', 'web', 'EUR', null, null, null, [], new \DateTimeImmutable('-1 hour')), $this->lead);
        $tracker->recordOrder(new OrderPayload('att-4', '999', 'web', 'EUR', $this->email->getId(), null, null, [], new \DateTimeImmutable('-1 hour'), 'cancelled'), $this->lead);

        $this->em->clear();
        $repository = $this->em->getRepository(EcommerceOrder::class);
        Assert::assertSame($this->campaign->getId(), $repository->findOneBy(['orderId' => 'att-1'])->getCampaignId());
        Assert::assertSame($this->campaign->getId(), $repository->findOneBy(['orderId' => 'att-2'])->getCampaignId());
        Assert::assertNull($repository->findOneBy(['orderId' => 'att-3'])->getCampaignId());

        /** @var EcommerceAnalytics $analytics */
        $analytics = static::getContainer()->get(EcommerceAnalytics::class);
        $from      = new \DateTime('-30 days');
        $to        = new \DateTime('+1 day');

        Assert::assertSame([['id' => (int) $this->campaign->getId(), 'name' => 'Win-back', 'orders' => 2, 'revenue' => 200.0]], $analytics->getRevenueByCampaign($from, $to, 10));
        Assert::assertSame('Win-back offer', $analytics->getRevenueByEmail($from, $to, 10)[0]['name']);

        $figures = $analytics->getKeyFigures($from, $to);
        Assert::assertSame(250.0, $figures['revenue']);
        Assert::assertSame(3, $figures['orders']);
        Assert::assertSame(1, $figures['customers']);
        Assert::assertSame(100.0, $figures['repeat_rate']);
        Assert::assertSame(200.0, $figures['attributed_revenue']);

        // The widgets render through the dashboard event.
        foreach ([DashboardSubscriber::REVENUE_IN_TIME, DashboardSubscriber::KEY_FIGURES, DashboardSubscriber::REVENUE_BY_CAMPAIGN] as $type) {
            $widget = new Widget();
            $widget->setType($type);
            $widget->setHeight(330);
            $widget->setParams(['dateFrom' => $from, 'dateTo' => $to, 'timeUnit' => 'D', 'dateFormat' => null]);

            $event = static::getContainer()->get(WidgetDetailEventFactory::class)->create($widget);
            static::getContainer()->get(DashboardSubscriber::class)->onWidgetDetailGenerate($event);

            Assert::assertNotEmpty($event->getTemplateData(), $type);
        }
    }
}
