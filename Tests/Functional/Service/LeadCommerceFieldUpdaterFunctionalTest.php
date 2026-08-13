<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadCommerceFieldUpdaterFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testOrderRecordingUpdatesContactCommerceFields(): void
    {
        $this->installCommerceFields();

        $lead = new Lead();
        $lead->setEmail('commerce-buyer@example.com');

        /** @var LeadModel $leadModel */
        $leadModel = static::getContainer()->get('mautic.lead.model.lead');
        $leadModel->saveEntity($lead);

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);

        $tracker->recordOrder(
            new OrderPayload('commerce-order-1', '75.5000', 'woocommerce', 'USD', null, null),
            $lead
        );

        $this->em->clear();
        $refreshedLead = $leadModel->getEntity($lead->getId());
        Assert::assertNotNull($refreshedLead);
        Assert::assertEqualsWithDelta(75.50, (float) $refreshedLead->getFieldValue(LeadCommerceFieldDefinition::LAST_ORDER_TOTAL), 0.001);
        Assert::assertEqualsWithDelta(75.50, (float) $refreshedLead->getFieldValue(LeadCommerceFieldDefinition::LIFETIME_VALUE), 0.001);
        Assert::assertSame(1, (int) $refreshedLead->getFieldValue(LeadCommerceFieldDefinition::ORDER_COUNT));
        Assert::assertNotEmpty($refreshedLead->getFieldValue(LeadCommerceFieldDefinition::LAST_ORDER_DATE));

        $tracker->recordOrder(
            new OrderPayload('commerce-order-2', '24.5000', 'woocommerce', 'USD', null, null),
            $refreshedLead
        );

        $this->em->clear();
        $updatedLead = $leadModel->getEntity($lead->getId());
        Assert::assertNotNull($updatedLead);
        Assert::assertSame(2, (int) $updatedLead->getFieldValue(LeadCommerceFieldDefinition::ORDER_COUNT));
        Assert::assertEqualsWithDelta(100.00, (float) $updatedLead->getFieldValue(LeadCommerceFieldDefinition::LIFETIME_VALUE), 0.001);
        Assert::assertEqualsWithDelta(24.50, (float) $updatedLead->getFieldValue(LeadCommerceFieldDefinition::LAST_ORDER_TOTAL), 0.001);
    }

    public function testCommerceFieldsAreRegisteredForSegmentation(): void
    {
        $this->installCommerceFields();

        /** @var FieldModel $fieldModel */
        $fieldModel = static::getContainer()->get('mautic.lead.model.field');

        foreach (LeadCommerceFieldDefinition::getFields() as $definition) {
            $field = $fieldModel->getRepository()->findOneBy(['alias' => $definition['alias']]);
            Assert::assertNotNull($field, sprintf('Missing field %s', $definition['alias']));
            Assert::assertTrue($field->getIsListable());
            Assert::assertTrue($field->getIsPublished());
        }
    }

    private function installCommerceFields(): void
    {
        /** @var FieldModel $fieldModel */
        $fieldModel = static::getContainer()->get('mautic.lead.model.field');
        /** @var TranslatorInterface $translator */
        $translator = static::getContainer()->get('translator');

        (new LeadCommerceFieldInstaller($fieldModel, $this->em, $translator, MAUTIC_TABLE_PREFIX))->installFields();
    }
}
