<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * The order list on the contact's eCommerce tab shows each order's products, not only in the order modal.
 */
class ContactEcommerceTabFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testOrderRowsListTheirProducts(): void
    {
        $lead = new Lead();
        $lead->setEmail('tab-buyer@example.com');
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder($this->payload('tab-1', [['Rose bath bomb', 1], ['Rose gift box', 2]]), $lead);
        $tracker->recordOrder($this->payload('tab-2', [['Item A', 1], ['Item B', 1], ['Item C', 1], ['Item D', 1], ['Item E', 1]]), $lead);

        $this->configureIntegration();
        $this->client->request(Request::METHOD_GET, '/s/contacts/view/'.$lead->getId());
        Assert::assertTrue($this->client->getResponse()->isOk());

        $rows = $this->client->getCrawler()->filter('tr.ecommerce-order-row');
        Assert::assertCount(2, $rows);
        $text = preg_replace('/\s+/u', ' ', implode(' ', $rows->each(static fn ($row): string => $row->text()))) ?? '';

        // Collapsed by default: an item count that toggles the hidden list.
        // Newest order first; the label follows the test user's locale ("5 items" / "5 Artikel").
        $toggles = $rows->filter('.ecommerce-order-items-toggle')->each(static fn ($a): string => trim(preg_replace('/\s+/u', ' ', $a->text()) ?? ''));
        Assert::assertCount(2, $toggles);
        Assert::assertMatchesRegularExpression('/^5 \S+$/u', $toggles[0]);
        Assert::assertMatchesRegularExpression('/^2 \S+$/u', $toggles[1]);
        Assert::assertCount(2, $rows->filter('.ecommerce-order-items.hide'));
        Assert::assertStringContainsString("1 \u{00D7} Rose bath bomb", $text);
        Assert::assertStringContainsString("2 \u{00D7} Rose gift box", $text);
        // Three items inline, the rest counted; the full list stays in the modal.
        Assert::assertStringContainsString("1 \u{00D7} Item C", $text);
        Assert::assertStringNotContainsString('Item D', $text);
        Assert::assertStringContainsString('+2', $text);
        Assert::assertCount(2, $rows->filter('a[data-toggle="modal"]'));
    }

    /**
     * @param list<array{0: string, 1: int}> $items name, qty
     */
    private function payload(string $orderId, array $items): OrderPayload
    {
        $itemPayloads = [];
        foreach ($items as $index => [$name, $qty]) {
            $itemPayloads[] = new OrderItemPayload($orderId.'-'.$index, $name, $qty, 'Bath', '10.00');
        }

        return new OrderPayload($orderId, '50', 'web', 'EUR', null, null, null, $itemPayloads, new \DateTimeImmutable('-1 hour'));
    }
}
