<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\SegmentFilterSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldDefinition;
use MauticPlugin\EcommerceConnectorBundle\Service\LeadCommerceFieldInstaller;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class SegmentFilterSubscriberFunctionalTest extends MauticMysqlTestCase
{
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
    }

    public function testSegmentFiltersMatchPurchasingContacts(): void
    {
        $this->installCommerceFields();

        $buyer = $this->createLead('buyer@example.com');
        $other = $this->createLead('other@example.com');

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload('segment-order-1', '150.0000', 'woocommerce', 'USD', null, null),
            $buyer
        );

        $segment = $this->createSegment([
            [
                'glue'     => 'and',
                'field'    => SegmentFilterSubscriber::FILTER_ORDER_SOURCE,
                'object'   => 'lead',
                'type'     => 'text',
                'operator' => '=',
                'filter'   => 'woocommerce',
                'display'  => null,
            ],
            [
                'glue'     => 'and',
                'field'    => SegmentFilterSubscriber::FILTER_ORDER_TOTAL,
                'object'   => 'lead',
                'type'     => 'number',
                'operator' => 'gte',
                'filter'   => '100',
                'display'  => null,
            ],
        ]);

        $this->rebuildSegment($segment);

        $memberLeadIds = $this->getSegmentMemberLeadIds($segment);
        Assert::assertContains($buyer->getId(), $memberLeadIds);
        Assert::assertNotContains($other->getId(), $memberLeadIds);
    }

    public function testHasOrderBooleanSegmentFilter(): void
    {
        $this->installCommerceFields();

        $buyer = $this->createLead('has-order-buyer@example.com');
        $other = $this->createLead('has-order-other@example.com');

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload('has-order-1', '25.0000', 'woocommerce', 'USD', null, null),
            $buyer
        );

        $yesSegment = $this->createSegment([
            [
                'glue'     => 'and',
                'field'    => SegmentFilterSubscriber::FILTER_HAS_ORDER,
                'object'   => 'lead',
                'type'     => 'boolean',
                'operator' => '=',
                'filter'   => '1',
                'display'  => null,
            ],
        ]);

        $this->rebuildSegment($yesSegment);

        $yesMemberLeadIds = $this->getSegmentMemberLeadIds($yesSegment);
        Assert::assertContains($buyer->getId(), $yesMemberLeadIds);
        Assert::assertNotContains($other->getId(), $yesMemberLeadIds);

        $noSegment = $this->createSegment([
            [
                'glue'     => 'and',
                'field'    => SegmentFilterSubscriber::FILTER_HAS_ORDER,
                'object'   => 'lead',
                'type'     => 'boolean',
                'operator' => '=',
                'filter'   => '0',
                'display'  => null,
            ],
        ]);

        $this->rebuildSegment($noSegment);

        $noMemberLeadIds = $this->getSegmentMemberLeadIds($noSegment);
        Assert::assertContains($other->getId(), $noMemberLeadIds);
        Assert::assertNotContains($buyer->getId(), $noMemberLeadIds);
    }

    public function testLifetimeValueContactFieldSegmentFilter(): void
    {
        $this->installCommerceFields();

        $buyer = $this->createLead('ltv-buyer@example.com');

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload('ltv-order-1', '200.0000', 'web', 'USD', null, null),
            $buyer
        );

        $segment = $this->createSegment([
            [
                'glue'     => 'and',
                'field'    => LeadCommerceFieldDefinition::LIFETIME_VALUE,
                'object'   => 'lead',
                'type'     => 'number',
                'operator' => 'gte',
                'filter'   => '150',
                'display'  => null,
            ],
        ]);

        $this->rebuildSegment($segment);

        $memberLeadIds = $this->getSegmentMemberLeadIds($segment);
        Assert::assertContains($buyer->getId(), $memberLeadIds);
    }

    public function testProductCategorySegmentFilter(): void
    {
        $this->installCommerceFields();

        $buyer = $this->createLead('category-buyer@example.com');
        $other = $this->createLead('category-other@example.com');

        /** @var EcommerceOrderTracker $tracker */
        $tracker = static::getContainer()->get(EcommerceOrderTracker::class);
        $tracker->recordOrder(
            new OrderPayload(
                'category-order-1',
                '0.0000',
                'WooCommerce',
                'USD',
                null,
                null,
                'hixson',
                [new OrderItemPayload('1722', 'Smart Water 1 Liter', 1, 'Beverages')]
            ),
            $buyer
        );
        $tracker->recordOrder(
            new OrderPayload(
                'category-order-2',
                '0.0000',
                'WooCommerce',
                'USD',
                null,
                null,
                'hixson',
                [new OrderItemPayload('3015', 'Swisher Sweet', 1, 'Cigars')]
            ),
            $other
        );

        $segment = $this->createSegment([
            [
                'glue'     => 'and',
                'field'    => SegmentFilterSubscriber::FILTER_PRODUCT_CATEGORY,
                'object'   => 'lead',
                'type'     => 'text',
                'operator' => '=',
                'filter'   => 'Beverages',
                'display'  => null,
            ],
        ]);

        $this->rebuildSegment($segment);

        $memberLeadIds = $this->getSegmentMemberLeadIds($segment);
        Assert::assertContains($buyer->getId(), $memberLeadIds);
        Assert::assertNotContains($other->getId(), $memberLeadIds);
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     */
    private function createSegment(array $filters): LeadList
    {
        $segment = new LeadList();
        $segment->setName('Ecommerce segment '.uniqid());
        $segment->setAlias('ecommerce_segment_'.uniqid());
        $segment->setPublicName('Ecommerce segment');
        $segment->setIsPublished(true);
        $segment->setFilters($filters);
        $this->em->persist($segment);
        $this->em->flush();

        return $segment;
    }

    private function createLead(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    private function rebuildSegment(LeadList $segment): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $applicationTester = new ApplicationTester($application);

        $exitCode = $applicationTester->run([
            'command' => 'mautic:segments:update',
            '-i'      => $segment->getId(),
            '--env'   => 'test',
        ]);

        Assert::assertSame(0, $exitCode, $applicationTester->getDisplay());
    }

    /**
     * @return int[]
     */
    private function getSegmentMemberLeadIds(LeadList $segment): array
    {
        return array_map(
            static fn (ListLead $member): int => (int) $member->getLead()->getId(),
            $this->em->getRepository(ListLead::class)->findBy(['list' => $segment->getId()])
        );
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
