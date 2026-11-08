<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Helper\ClickthroughHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEvent;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceEventRepository;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontEventFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['enable_storefront_events' => 1]);

        $this->shopper = new Lead();
        $this->shopper->setEmail('shopper@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($this->shopper);
    }

    public function testSignedWebhookRecordsBatchForKnownContactsOnly(): void
    {
        $this->postSignedEvent([
            'events' => [
                ['event' => 'viewed_product', 'email' => 'shopper@example.com', 'product' => ['id' => 'CND-1', 'name' => 'Candle']],
                ['event' => 'add_to_cart', 'email' => 'shopper@example.com', 'cart' => ['id' => 'c1', 'items' => [['item_id' => 'CND-1', 'name' => 'Candle', 'qty' => 2, 'price' => '10']]]],
                ['event' => 'viewed_product', 'email' => 'stranger@example.com', 'product' => ['id' => 'CND-2']],
            ],
        ]);

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        Assert::assertSame(['added_to_cart', 'viewed_product'], $this->eventTypes());
        Assert::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX."leads WHERE email = 'stranger@example.com'"));

        // Unsigned: ignored.
        $this->client->request(Request::METHOD_POST, '/ecommerce/event', [], [], ['CONTENT_TYPE' => 'application/json'], '{"event":"viewed_product","email":"shopper@example.com","product_id":"X"}');
        Assert::assertCount(2, $this->eventTypes());
    }

    public function testGuestFromAMauticEmailIsIdentifiedByTheSendsTrackingHash(): void
    {
        $other = new Lead();
        $other->setEmail('other@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($other);

        $stat = new Stat();
        $stat->setLead($this->em->getReference(Lead::class, $this->shopper->getId()));
        $stat->setEmailAddress('shopper@example.com');
        $stat->setTrackingHash('6aef89be59f70782047236');
        $stat->setDateSent(new \DateTime());
        $this->em->persist($stat);
        $this->em->flush();

        // As Mautic encodes it in email links. The "lead" in it names another contact and must be ignored.
        $ct = ClickthroughHelper::encodeArrayForUrl(['source' => ['email', 1], 'email' => 1, 'stat' => '6aef89be59f70782047236', 'lead' => $other->getId()]);

        $this->postSignedEvent(['event' => 'viewed_product', 'ct' => $ct, 'product' => ['id' => 'CND-1', 'name' => 'Candle']]);
        $this->postSignedEvent(['event' => 'viewed_product', 'stat' => '6aef89be59f70782047236', 'product' => ['id' => 'CND-2', 'name' => 'Soap']]);
        // Unknown hash, and a forged "ct" without a real hash: nobody.
        $this->postSignedEvent(['event' => 'viewed_product', 'stat' => 'ffffffffffffffffffffff', 'product' => ['id' => 'CND-3']]);
        $this->postSignedEvent(['event' => 'viewed_product', 'ct' => ClickthroughHelper::encodeArrayForUrl(['lead' => $other->getId()]), 'product' => ['id' => 'CND-4']]);

        $rows = $this->connection->fetchAllKeyValue('SELECT product_id, lead_id FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_events ORDER BY product_id');
        Assert::assertSame(['CND-1' => (string) $this->shopper->getId(), 'CND-2' => (string) $this->shopper->getId()], array_map('strval', $rows));
    }

    public function testPingAnswersOnlyCorrectlySignedRequests(): void
    {
        $body = '{"ping":true}';
        $this->client->request(Request::METHOD_POST, '/ecommerce/ping', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);

        Assert::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        Assert::assertTrue($answer['ok']);
        Assert::assertTrue($answer['storefront_events']);
        Assert::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $answer['version']);

        $this->client->request(Request::METHOD_POST, '/ecommerce/ping', [], [], [
            'CONTENT_TYPE'                      => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE' => hash_hmac('sha256', $body, 'wrong secret'),
        ], $body);

        Assert::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        Assert::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function testNothingIsRecordedWhileSwitchedOff(): void
    {
        $this->configureIntegration(['enable_storefront_events' => 0]);
        $this->postSignedEvent(['event' => 'viewed_product', 'email' => 'shopper@example.com', 'product_id' => 'CND-1']);

        Assert::assertSame([], $this->eventTypes());
    }

    public function testApiCreatesAndListsEvents(): void
    {
        $this->client->request(Request::METHOD_POST, '/api/ecommerce/events/new', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'event'      => 'started_checkout',
            'contact_id' => $this->shopper->getId(),
            'cart'       => ['total' => '49.90', 'url' => 'https://shop.example/cart/restore'],
        ], JSON_THROW_ON_ERROR));
        Assert::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/api/contacts/'.$this->shopper->getId().'/ecommerce/events');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        Assert::assertSame(1, $body['total']);
        Assert::assertSame('started_checkout', $body['events'][0]['event']);
        Assert::assertSame('49.9000', $body['events'][0]['cart']['total']);
    }

    public function testCleanupAndContactDeleteRemoveEvents(): void
    {
        $this->postSignedEvent(['event' => 'viewed_product', 'email' => 'shopper@example.com', 'product_id' => 'CND-1']);
        $this->connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX.'ecommerce_events SET occurred_at = DATE_SUB(NOW(), INTERVAL 91 DAY)');
        $this->postSignedEvent(['event' => 'viewed_product', 'email' => 'shopper@example.com', 'product_id' => 'CND-2']);

        $this->testSymfonyCommand('mautic:maintenance:cleanup', ['--days-old' => 365, '--no-interaction' => true]);
        Assert::assertCount(1, $this->eventTypes(), 'Only the event past 90 days is removed.');

        static::getContainer()->get('mautic.lead.model.lead')->deleteEntity($this->shopper);
        Assert::assertSame([], $this->eventTypes());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postSignedEvent(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/ecommerce/event', [], [], [
            'CONTENT_TYPE'                       => 'application/json',
            'HTTP_X_MAUTIC_ECOMMERCE_SIGNATURE'  => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function eventTypes(): array
    {
        return $this->connection->fetchFirstColumn('SELECT event_type FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_events ORDER BY event_type');
    }
}
