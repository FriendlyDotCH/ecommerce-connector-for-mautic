<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrder;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItem;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadLifecycleSubscriberFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testDeletingContactDeletesOrdersAndLineItems(): void
    {
        $keep  = $this->createLead('keep-orders@example.com');
        $purge = $this->createLead('purge-orders@example.com');

        $this->recordOrder($keep, 'keep-order-1', '12.00', [
            new OrderItemPayload('sku-keep', 'Kept item', 1, 'Keep'),
        ]);
        $this->recordOrder($purge, 'purge-order-1', '55.00', [
            new OrderItemPayload('sku-purge', 'Purged item', 3, 'Purge'),
        ]);

        /** @var LeadModel $leadModel */
        $leadModel = static::getContainer()->get(LeadModel::class);
        $leadModel->deleteEntity($purge);

        $this->em->clear();

        Assert::assertNull($this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'purge-order-1']));
        Assert::assertNotNull($this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'keep-order-1']));
        Assert::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items WHERE item_id = :itemId',
                ['itemId' => 'sku-purge']
            )
        );
        Assert::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'ecommerce_order_items WHERE item_id = :itemId',
                ['itemId' => 'sku-keep']
            )
        );
    }

    public function testMergingContactsMovesOrdersToWinner(): void
    {
        $this->installCommerceFields();

        $winner = $this->createLead('winner@example.com');
        $loser  = $this->createLead('loser@example.com');

        $this->recordOrder($winner, 'winner-order-1', '10.00');
        $this->recordOrder($loser, 'loser-order-1', '15.00', [
            new OrderItemPayload('sku-loser', 'Moved item', 1, 'Merge'),
        ]);

        /** @var ContactMerger $merger */
        $merger = static::getContainer()->get(ContactMerger::class);
        $merger->merge($winner, $loser);

        $this->em->clear();

        $moved = $this->em->getRepository(EcommerceOrder::class)->findOneBy(['orderId' => 'loser-order-1']);
        Assert::assertNotNull($moved);
        Assert::assertNotNull($moved->getLead());
        Assert::assertSame($winner->getId(), $moved->getLead()->getId());

        $movedItem = $this->em->getRepository(EcommerceOrderItem::class)->findOneBy(['itemId' => 'sku-loser']);
        Assert::assertNotNull($movedItem);
        Assert::assertNotNull($movedItem->getLead());
        Assert::assertSame($winner->getId(), $movedItem->getLead()->getId());

        /** @var LeadModel $leadModel */
        $leadModel     = static::getContainer()->get(LeadModel::class);
        $refreshedLead = $leadModel->getEntity($winner->getId());
        Assert::assertNotNull($refreshedLead);
        Assert::assertSame(2, (int) $refreshedLead->getFieldValue(LeadCommerceFieldDefinition::ORDER_COUNT));
        Assert::assertEqualsWithDelta(25.00, (float) $refreshedLead->getFieldValue(LeadCommerceFieldDefinition::LIFETIME_VALUE), 0.001);
    }

    /**
     * @param list<OrderItemPayload> $items
     */
    private function recordOrder(Lead $lead, string $orderId, string $total, array $items = []): void
    {
        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload($orderId, $total, 'woocommerce', 'USD', null, null, 'Hixson', $items),
            $lead
        );
    }

    private function createLead(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        /** @var LeadModel $leadModel */
        $leadModel = static::getContainer()->get(LeadModel::class);
        $leadModel->saveEntity($lead);

        return $lead;
    }

    private function installCommerceFields(): void
    {
        /** @var FieldModel $fieldModel */
        $fieldModel = static::getContainer()->get(FieldModel::class);
        /** @var TranslatorInterface $translator */
        $translator = static::getContainer()->get(TranslatorInterface::class);

        (new LeadCommerceFieldInstaller($fieldModel, $this->em, $translator, MAUTIC_TABLE_PREFIX))->installFields();
    }
}
