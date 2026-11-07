<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Service\EcbRateFetcher;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition as F;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

class BaseCurrencyFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration(['base_currency' => 'HUF', 'exchange_rates' => 'EUR=400']);
        static::getContainer()->get(LeadCommerceFieldInstaller::class)->installFields();

        // ECB: 1 EUR = 1.25 USD = 400 HUF, so 1 USD = 320 HUF.
        static::getContainer()->get(EcbRateFetcher::class)->store([
            ['date' => '2026-01-02', 'currency' => 'USD', 'per_eur' => 1.25],
            ['date' => '2026-01-02', 'currency' => 'HUF', 'per_eur' => 400.0],
        ]);

        $this->buyer = new Lead();
        $this->buyer->setEmail('multi-currency@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($this->buyer);
    }

    public function testOrdersAreConvertedAtTheirOrderDate(): void
    {
        $this->record('fx-huf', '5000', 'HUF');
        $this->record('fx-eur', '10', 'EUR');  // manual rate
        $this->record('fx-usd', '10', 'USD');  // ECB rate
        $this->record('fx-gbp', '10', 'GBP');  // no rate: face value

        Assert::assertSame('4000.0000', $this->order('fx-eur')->getBaseNetTotal());
        Assert::assertSame('3200.0000', $this->order('fx-usd')->getBaseNetTotal());
        Assert::assertNull($this->order('fx-gbp')->getExchangeRate());

        // 5000 + 4000 + 3200 + 10.
        Assert::assertEqualsWithDelta(12210.0, $this->field(F::LIFETIME_VALUE), 0.01);

        // A new manual rate applies to existing orders only after a rebase.
        $this->configureIntegration(['base_currency' => 'HUF', 'exchange_rates' => 'EUR=410, GBP=500']);
        $output = $this->testSymfonyCommand('mautic:ecommerce:exchange-rates', ['--rebase' => true]);
        Assert::assertStringContainsString('4 orders converted, 0 without a rate', $output->getDisplay());
        Assert::assertSame('4100.0000', $this->order('fx-eur')->getBaseNetTotal());
        Assert::assertSame('5000.0000', $this->order('fx-gbp')->getBaseNetTotal());

        $this->testSymfonyCommand('mautic:ecommerce:recalculate');
        Assert::assertEqualsWithDelta(17300.0, $this->field(F::LIFETIME_VALUE), 0.01);
    }

    private function record(string $orderId, string $total, string $currency): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(
            new OrderPayload($orderId, $total, 'web', $currency, null, null, null, [], new \DateTimeImmutable('2026-03-01')),
            $this->buyer
        );
    }

    private function order(string $orderId): EcommerceOrder
    {
        $this->em->clear();

        return $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => $orderId]);
    }

    private function field(string $alias): float
    {
        return (float) $this->connection->fetchOne(sprintf('SELECT %s FROM %sleads WHERE id = ?', $alias, MAUTIC_TABLE_PREFIX), [$this->buyer->getId()]);
    }
}
