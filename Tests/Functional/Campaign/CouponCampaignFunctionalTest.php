<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Campaign;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponGateway;
use MauticPlugin\EcommerceConnectorBundle\Coupon\CouponStore;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CouponSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CouponCampaignFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    private const COUPON_URL = 'https://shop.example/wp-json/mautic-ecommerce/v1/coupon';

    protected $useCleanupRollback = false;

    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>, raw: string}> */
    private array $requests = [];

    /** @var list<MockResponse|callable> answers in order; default: created */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->get(IpLookupHelper::class)->reset();
        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration([], ['coupon_url' => self::COUPON_URL]);

        $this->requests = [];
        $this->answers  = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $raw     = (string) $options['body'];
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$name, $value]             = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($raw, true), 'raw' => $raw];

            $answer = array_shift($this->answers);
            if (null !== $answer) {
                return is_callable($answer) ? $answer($this->requests[array_key_last($this->requests)]) : $answer;
            }

            $code = json_decode($raw, true)['code'];

            return new MockResponse(json_encode(['ok' => true, 'code' => strtolower($code), 'store' => 'woocommerce']), ['http_code' => 201]);
        });
        static::getContainer()->set(CouponGateway::class, new CouponGateway($client, static::getContainer()->get(Config::class)));
    }

    public function testCampaignIssuesSignedCouponShownInEmailAndRedeemedByOrder(): void
    {
        $jane    = $this->createContact('jane@example.com');
        $noEmail = $this->createContact(null);
        $campaign = $this->createCouponCampaign(['discount_type' => 'percent', 'amount' => 15, 'valid_days' => 10, 'prefix' => 'Back', 'minimum_amount' => 50], [$jane, $noEmail]);

        $this->testSymfonyCommand('mautic:campaigns:trigger', ['-i' => $campaign->getId()]);

        Assert::assertCount(1, $this->requests, 'Only the contact with an email gets a coupon.');
        $request = $this->requests[0];
        Assert::assertSame(self::COUPON_URL, $request['url']);
        Assert::assertSame(hash_hmac('sha256', $request['raw'], self::WEBHOOK_SECRET), $request['headers']['x-mautic-ecommerce-signature']);
        Assert::assertMatchesRegularExpression('/^BACK-[A-HJKMNP-Z2-9]{8}$/', $request['body']['code']);
        Assert::assertSame('jane@example.com', $request['body']['email']);
        Assert::assertSame('percent', $request['body']['discount_type']);
        Assert::assertSame('15.00', $request['body']['amount']);
        Assert::assertSame('50.00', $request['body']['minimum_amount']);
        Assert::assertSame((new \DateTimeImmutable('+10 days', new \DateTimeZone('UTC')))->format('Y-m-d'), $request['body']['expires_at']);
        Assert::assertEqualsWithDelta(time(), $request['body']['timestamp'], 60);

        $code = $request['body']['code'];
        Assert::assertSame([$code], $this->connection->fetchFirstColumn('SELECT code FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons WHERE lead_id = ?', [$jane->getId()]));
        Assert::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons WHERE lead_id = ?', [$noEmail->getId()]));

        // The email shows the code, value and last day.
        $tokens = $this->tokensFor($jane);
        Assert::assertSame($code, $tokens['{ecommerce_coupon}']);
        Assert::assertSame('15 %', $tokens['{ecommerce_coupon_value}']);
        Assert::assertNotSame('', $tokens['{ecommerce_coupon_expiry}']);

        // Used on an order (shops send codes lower case): redeemed, the condition is true, and the token is empty.
        Assert::assertFalse(static::getContainer()->get(CouponStore::class)->hasRedeemedInCampaign((int) $jane->getId(), (int) $campaign->getId()));
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload('CPN-1', '80', 'woocommerce', 'EUR', null, null, null, [], null, null, null, [strtolower($code)]),
            $this->em->getRepository(Lead::class)->find($jane->getId())
        );
        Assert::assertNotNull($this->connection->fetchOne('SELECT redeemed_order_id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons WHERE code = ?', [$code]));
        Assert::assertTrue(static::getContainer()->get(CouponStore::class)->hasRedeemedInCampaign((int) $jane->getId(), (int) $campaign->getId()));
        Assert::assertSame('', $this->tokensFor($jane)['{ecommerce_coupon}']);
    }

    public function testShopDownAndRefusalBothFailForRetry(): void
    {
        $down    = $this->createContact('down@example.com');
        $refused = $this->createContact('refused@example.com');
        $this->answers = [
            static fn (array $request): MockResponse => new MockResponse('', ['http_code' => 503]),
            static fn (array $request): MockResponse => new MockResponse(json_encode(['ok' => false, 'message' => 'Coupons are not allowed']), ['http_code' => 403]),
        ];
        $campaign = $this->createCouponCampaign(['discount_type' => 'fixed_cart', 'amount' => 10, 'valid_days' => 14], [$down, $refused]);

        $this->testSymfonyCommand('mautic:campaigns:trigger', ['-i' => $campaign->getId()]);

        Assert::assertCount(2, $this->requests);
        Assert::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_coupons'));

        $logs = $this->connection->fetchAllAssociativeIndexed(
            'SELECT l.lead_id, l.is_scheduled, l.trigger_date, l.metadata FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log l ORDER BY l.lead_id'
        );
        // Neither passes, so an email after the action never goes out without a code.
        Assert::assertSame('1', (string) $logs[$down->getId()]['is_scheduled']);
        Assert::assertSame('1', (string) $logs[$refused->getId()]['is_scheduled']);
        Assert::assertGreaterThan(strtotime((string) $logs[$down->getId()]['trigger_date']), strtotime((string) $logs[$refused->getId()]['trigger_date']), 'A refusal is retried later than an outage.');
        Assert::assertStringContainsString('Coupons are not allowed', (string) $logs[$refused->getId()]['metadata']);
    }

    /**
     * @return array<string, string>
     */
    private function tokensFor(Lead $lead): array
    {
        $send = new EmailSendEvent(null, ['content' => '{ecommerce_coupon} {ecommerce_coupon_value} {ecommerce_coupon_expiry}', 'lead' => ['id' => $lead->getId()]]);
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($send, EmailEvents::EMAIL_ON_DISPLAY);

        return $send->getTokens();
    }

    private function createContact(?string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $lead->setFirstname('Test');
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<Lead>           $members
     */
    private function createCouponCampaign(array $properties, array $members): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName('Coupon');
        $campaign->setIsPublished(true);

        $action = new Event();
        $action->setOrder(1);
        $action->setName('Issue coupon');
        $action->setType(CouponSubscriber::ACTION_ISSUE_COUPON);
        $action->setEventType('action');
        $action->setTriggerMode('immediate');
        $action->setProperties($properties);
        $action->setCampaign($campaign);

        $campaign->addEvents([$action]);
        $this->em->persist($campaign);
        $this->em->persist($action);

        foreach ($members as $member) {
            $campaignMember = new CampaignMember();
            $campaignMember->setLead($member);
            $campaignMember->setCampaign($campaign);
            $campaignMember->setDateAdded(new \DateTime('-1 hour'));
            $campaign->addLead(0, $campaignMember);
            $this->em->persist($campaignMember);
        }

        $this->em->flush();
        $this->em->clear();

        return $campaign;
    }
}
