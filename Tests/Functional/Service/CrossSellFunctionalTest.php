<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\CrossSellSegments;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CrossSellFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['create_cross_sell_segments' => 1]);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();
    }

    public function testNightlyRecalculationFillsFieldsSegmentsAndBlocks(): void
    {
        $products = static::getContainer()->get(EcommerceProductRepository::class);
        foreach ([['155', 'Lavender Candle', 'Candles'], ['160', 'Reed Diffuser', 'Diffusers'], ['161', 'Cedar Diffuser', 'Diffusers'], ['170', 'Olive Soap', 'Soap']] as [$id, $name, $category]) {
            $products->upsertProduct('woocommerce', ['external_id' => $id, 'name' => $name, 'category' => $category, 'categories' => [$category], 'price' => '20', 'stock_status' => 'instock', 'url' => 'https://shop.example/p/'.$id, 'status' => 'publish']);
        }

        // 6 customers bought candles and diffusers (most the reed diffuser), 2 only soap.
        for ($i = 1; $i <= 6; ++$i) {
            $this->order($this->contact("pair{$i}@example.com"), "P-{$i}", [['155', 'Candles'], [$i <= 4 ? '160' : '161', 'Diffusers']]);
        }
        for ($i = 1; $i <= 2; ++$i) {
            $this->order($this->contact("soap{$i}@example.com"), "S-{$i}", [['170', 'Soap']]);
        }
        $candleOnly = $this->contact('candle@example.com');
        $this->order($candleOnly, 'C-1', [['155', 'Candles']]);

        $this->testSymfonyCommand('mautic:ecommerce:recalculate');

        $field = fn (Lead $lead, string $column): ?string => $this->connection->fetchOne('SELECT '.$column.' FROM '.MAUTIC_TABLE_PREFIX.'leads WHERE id = ?', [$lead->getId()]) ?: null;
        Assert::assertSame('Diffusers', $field($candleOnly, 'ecommerce_cross_sell_category'));
        Assert::assertSame('Candles', $field($candleOnly, 'ecommerce_cross_sell_from'));

        $soapBuyer = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'soap1@example.com']);
        Assert::assertNull($field($soapBuyer, 'ecommerce_cross_sell_category'), 'No rule from soap.');

        // One segment per opportunity, created once.
        Assert::assertSame(['Cross-sell: Diffusers'], $this->connection->fetchFirstColumn('SELECT name FROM '.MAUTIC_TABLE_PREFIX."lead_lists WHERE alias LIKE 'ecommerce-cross-sell-%'"));
        $this->testSymfonyCommand('mautic:ecommerce:recalculate');
        Assert::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'lead_lists WHERE alias = ?', [CrossSellSegments::alias('Diffusers')]));

        // A later order updates the contact straight away, with the nightly model.
        $this->order($candleOnly, 'C-2', [['160', 'Diffusers']]);
        Assert::assertNull($field($candleOnly, 'ecommerce_cross_sell_category'), 'Bought diffusers now.');

        // A cache clear (deploy) loses the nightly model: it is rebuilt, not skipped.
        static::getContainer()->get('cache.app')->clear();
        $late = $this->contact('late@example.com');
        $this->order($late, 'L-1', [['155', 'Candles']]);
        Assert::assertSame('Diffusers', $field($late, 'ecommerce_cross_sell_category'));
        Assert::assertNotEmpty(static::getContainer()->get(\MauticPlugin\EcommerceConnectorBundle\Service\CrossSellCalculator::class)->rules());

                // The block shows best-selling diffusers to someone with that opportunity.
        $other = $this->contact('candle2@example.com');
        $this->order($other, 'C-3', [['155', 'Candles']]);
        $send = new EmailSendEvent(null, ['content' => '<div>{ecommerce_products source="cross_sell" limit="2"}</div>', 'lead' => ['id' => $other->getId()]]);
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($send, EmailEvents::EMAIL_ON_DISPLAY);
        Assert::assertMatchesRegularExpression('/Reed Diffuser.*Cedar Diffuser/s', $send->getContent());
        Assert::assertStringNotContainsString('Candle', $send->getContent());
    }

    private function contact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }

    /**
     * @param list<array{0: string, 1: string}> $lines product ID and category
     */
    private function order(Lead $lead, string $orderId, array $lines): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload($orderId, '40', 'woocommerce', 'EUR', null, null, null, array_map(
            static fn (array $line): OrderItemPayload => new OrderItemPayload('SKU-'.$line[0], 'Line '.$line[0], 1, $line[1], '20', 0, $line[0]),
            $lines
        ), new \DateTimeImmutable('-3 days')), $this->em->getRepository(Lead::class)->find($lead->getId()));
    }
}
