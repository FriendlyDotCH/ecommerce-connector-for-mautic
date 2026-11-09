<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventParser;
use MauticPlugin\EcommerceConnectorBundle\Service\StorefrontEventRecorder;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Service\OpportunityFinder;
use MauticPlugin\EcommerceConnectorBundle\Service\OpportunitySegments;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class OpportunityFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['enable_storefront_events' => 1]);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();
    }

    public function testPageListsOpportunitiesAndSegmentsMatchThem(): void
    {
        // 6 customers: candles, then diffusers 30 days later (cross-sell rule, second-order gap 30).
        for ($i = 1; $i <= 6; ++$i) {
            $lead = $this->contact("pair{$i}@example.com");
            $this->order($lead, "P{$i}-1", 'Candles', '40', 100);
            $this->order($lead, "P{$i}-2", 'Diffusers', '40', 70);
        }
        // Due: 30-day rhythm, last order 35 days ago, so the next one was expected 5 days ago.
        $due = $this->contact('due@example.com');
        $this->order($due, 'D-1', 'Soap', '40', 65);
        $this->order($due, 'D-2', 'Soap', '40', 35);
        // One order 30 days ago, candles only: second order window and diffusers cross-sell.
        $single = $this->contact('single@example.com');
        $this->order($single, 'S-1', 'Candles', '40', 30);
        // A big spender overdue against a 50-day rhythm.
        $big = $this->contact('big@example.com');
        $this->order($big, 'B-1', 'Soap', '500', 200);
        $this->order($big, 'B-2', 'Soap', '500', 150);

        // Interest: views on two days (updated straight away), a cart from a contact without
        // orders, and views of something already bought (no interest).
        static::getContainer()->get(EcommerceProductRepository::class)->upsertProduct('woocommerce', ['external_id' => '160', 'name' => 'Reed Diffuser', 'price' => '30', 'category' => 'Diffusers', 'stock_status' => 'instock']);
        $this->event($single, ['event' => 'viewed_product', 'product_id' => '160', 'product_name' => 'Reed Diffuser', 'category' => 'Diffusers', 'occurred_at' => gmdate(DATE_ATOM, strtotime('-3 days'))]);
        $this->event($single, ['event' => 'viewed_product', 'product_id' => '160', 'product_name' => 'Reed Diffuser', 'category' => 'Diffusers', 'occurred_at' => gmdate(DATE_ATOM, strtotime('-2 days'))]);
        Assert::assertSame('Reed Diffuser', $this->field($single, 'ecommerce_interest_product'));
        $browser = $this->contact('browser@example.com');
        $this->event($browser, ['event' => 'added_to_cart', 'cart_id' => 'c-1', 'occurred_at' => gmdate(DATE_ATOM, strtotime('-4 days')), 'items' => [['item_id' => 'SKU-RD', 'item_name' => 'Reed Diffuser', 'qty' => 1, 'unit_price' => '30', 'category' => 'Diffusers']]]);
        $pair = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'pair1@example.com']);
        $this->event($pair, ['event' => 'viewed_product', 'product_name' => 'Candles item', 'occurred_at' => gmdate(DATE_ATOM, strtotime('-3 days'))]);
        $this->event($pair, ['event' => 'viewed_product', 'product_name' => 'Candles item', 'occurred_at' => gmdate(DATE_ATOM, strtotime('-2 days'))]);

        $this->testSymfonyCommand('mautic:ecommerce:recalculate');

        Assert::assertSame('Diffusers', $this->field($browser, 'ecommerce_interest_category'));
        Assert::assertNull($this->field($pair, 'ecommerce_interest_product'), 'Already bought.');
        Assert::assertSame('Soap', $this->field($big, 'ecommerce_lapsed_category'));
        Assert::assertSame('150', $this->field($big, 'ecommerce_lapsed_category_days'));
        Assert::assertNull($this->field($due, 'ecommerce_lapsed_category'), 'Within the 60-day minimum.');

        $found = [];
        foreach (static::getContainer()->get(OpportunityFinder::class)->findAll() as $opportunity) {
            $found[$opportunity['key']] = $opportunity;
        }
        Assert::assertArrayHasKey('cross_sell:Diffusers', $found);
        Assert::assertSame(1, $found['due_reorder']['contacts']);
        Assert::assertSame(1, $found['second_order']['contacts']);
        Assert::assertSame(['%from%' => 30, '%to%' => 30, '%basis%' => 'measured'], $found['second_order']['params']);
        Assert::assertSame(40.0, $found['cross_sell:Diffusers']['typical_order'], 'Average order containing diffusers.');
        Assert::assertArrayHasKey('vip_slipping', $found);
        Assert::assertSame(500.0, $found['vip_slipping']['typical_order']);

        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/opportunities');
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = $crawler->filter('#ecommerceOpportunityTable tbody tr');
        Assert::assertCount(count($found), $rows);
        $potentials = array_map(static fn (string $key): float => $found[$key]['potential'], $rows->each(static fn ($row): string => (string) $row->attr('data-opportunity')));
        $sorted     = $potentials;
        rsort($sorted);
        Assert::assertSame($sorted, $potentials, 'Largest potential first.');
        $trans = static::getContainer()->get('translator');
        Assert::assertStringContainsString($trans->trans('mautic.plugin.ecommerce.opportunity.type.cross_sell.title', ['%category%' => 'Diffusers']), $crawler->filter('tr[data-opportunity="cross_sell:Diffusers"]')->text());
        Assert::assertCount(count($found), $crawler->filter('.ecommerce-opportunity-create'));

        // Not without the ajax CSRF header.
        $this->client->request(Request::METHOD_POST, '/s/ecommerce/opportunities/segment?key=due_reorder');
        $this->client->xmlHttpRequest(Request::METHOD_POST, '/s/ecommerce/opportunities/segment?key=due_reorder');
        Assert::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX."lead_lists WHERE alias LIKE 'ecommerce-opportunity-%'"));

        // One segment per opportunity, with the same members the page counts.
        foreach ($found as $key => $opportunity) {
            $this->client->request(Request::METHOD_POST, '/s/ecommerce/opportunities/segment?key='.rawurlencode($key), [], [], $this->createAjaxHeaders());
            Assert::assertTrue($this->client->getResponse()->isOk(), $key);
            $id = $this->connection->fetchOne('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias = ?', [OpportunitySegments::alias($key)]);
            Assert::assertNotFalse($id, $key);

            $this->testSymfonyCommand('mautic:segments:update', ['--list-id' => $id]);
            $members = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'lead_lists_leads WHERE leadlist_id = ? AND manually_removed = 0', [$id]);
            Assert::assertSame($opportunity['contacts'], $members, "Segment for {$key} matches the page.");
        }

        $members = fn (string $key): array => array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT ll.lead_id FROM '.MAUTIC_TABLE_PREFIX.'lead_lists_leads ll JOIN '.MAUTIC_TABLE_PREFIX.'lead_lists l ON l.id = ll.leadlist_id WHERE l.alias = ?',
            [OpportunitySegments::alias($key)]
        ));
        Assert::assertSame([(int) $due->getId()], $members('due_reorder'));
        Assert::assertSame([(int) $single->getId()], $members('second_order'));
        Assert::assertContains((int) $big->getId(), $members('vip_slipping'));
        Assert::assertContains((int) $single->getId(), $members('cross_sell:Diffusers'));
        $interested = $members('interested');
        sort($interested);
        Assert::assertSame([(int) $single->getId(), (int) $browser->getId()], $interested);
        Assert::assertSame([(int) $big->getId()], $members('lapsed_category'));
        Assert::assertSame(30.0, $found['interested']['typical_order'], 'The price of the product.');
        Assert::assertSame('Reed Diffuser (2)', $found['interested']['params']['%examples%']);

        // Asking again finds the segment; the page links it.
        $this->client->request(Request::METHOD_POST, '/s/ecommerce/opportunities/segment?key=due_reorder', [], [], $this->createAjaxHeaders());
        Assert::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias = ?', [OpportunitySegments::alias('due_reorder')]));
        $crawler = $this->client->request(Request::METHOD_GET, '/s/ecommerce/opportunities');
        Assert::assertCount(0, $crawler->filter('.ecommerce-opportunity-create'));
        $title = $trans->trans('mautic.plugin.ecommerce.opportunity.type.due_reorder.title');
        Assert::assertStringContainsString($trans->trans('mautic.plugin.ecommerce.opportunity.segment_name', ['%title%' => $title]), $crawler->filter('tr[data-opportunity="due_reorder"] .ecommerce-opportunity-segment')->text());

        // An opportunity that no longer exists makes no segment.
        $this->client->request(Request::METHOD_POST, '/s/ecommerce/opportunities/segment?key=cross_sell:Nothing', [], [], $this->createAjaxHeaders());
        Assert::assertFalse($this->connection->fetchOne('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias = ?', [OpportunitySegments::alias('cross_sell:Nothing')]));
    }

    public function testEmptyPageExplainsWhenOpportunitiesAppear(): void
    {
        $this->client->request(Request::METHOD_GET, '/s/ecommerce/opportunities');
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertStringContainsString('mautic:ecommerce:recalculate', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function event(Lead $lead, array $input): void
    {
        $payload = static::getContainer()->get(StorefrontEventParser::class)->parse($input);
        Assert::assertNotNull($payload);
        static::getContainer()->get(StorefrontEventRecorder::class)->record($payload, $this->em->getRepository(Lead::class)->find($lead->getId()));
    }

    private function field(Lead $lead, string $column): ?string
    {
        $value = $this->connection->fetchOne('SELECT '.$column.' FROM '.MAUTIC_TABLE_PREFIX.'leads WHERE id = ?', [$lead->getId()]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function contact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    private function order(Lead $lead, string $orderId, string $category, string $total, int $daysAgo): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload($orderId, $total, 'woocommerce', 'EUR', null, null, null, [
            new OrderItemPayload('SKU-'.$category, $category.' item', 1, $category, $total),
        ], new \DateTimeImmutable("-{$daysAgo} days")), $this->em->getRepository(Lead::class)->find($lead->getId()));
    }
}
