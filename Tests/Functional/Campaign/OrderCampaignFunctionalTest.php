<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\Campaign;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\Tag;
use MauticPlugin\EcommerceConnectorBundle\EventSubscriber\CampaignSubscriber;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;

/**
 * Signed webhook orders run "Places an order" and "Purchase history" for the order's contact.
 */
class OrderCampaignFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        // IpLookupHelper caches IP entities in a static array that outlives the test database reset.
        // Since Mautic 7.2 campaign logs are written with a raw upsert, so a stale cached ip_id breaks the foreign key.
        static::getContainer()->get(IpLookupHelper::class)->reset();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();
    }

    public function testMatchingRecentOrderRunsDecisionActions(): void
    {
        $contact = $this->createContact('candle-fan@example.com');
        $this->createCampaign($contact, [
            'min_total'  => 40,
            'categories' => 'Candles',
            'products'   => '',
            'sources'    => '',
        ], null, 'candle-buyer');

        // Too old to trigger, even though it matches.
        $this->postSignedJsonOrder($this->order('old-1', 60, 'Candles', 'candle-fan@example.com', '-2 days'));
        // Recent, but no candles.
        $this->postSignedJsonOrder($this->order('soap-1', 60, 'Bath', 'candle-fan@example.com'));
        // Recent candles, but below the minimum.
        $this->postSignedJsonOrder($this->order('small-1', 20, 'Candles', 'candle-fan@example.com'));

        Assert::assertSame([], $this->tagsOf($contact));

        $this->postSignedJsonOrder($this->order('match-1', 45, 'Candles', 'candle-fan@example.com'));

        Assert::assertSame(['candle-buyer'], $this->tagsOf($contact));
    }

    public function testPurchaseHistoryConditionSeesTheNewOrder(): void
    {
        $bigSpender   = $this->createContact('big@example.com');
        $smallSpender = $this->createContact('small@example.com');

        $condition = [
            'metric'   => 'spend',
            'operator' => 'gte',
            'value'    => 50,
            'days'     => 30,
            'scope'    => 'any',
            'values'   => '',
        ];
        $this->createCampaign($bigSpender, ['min_total' => null, 'categories' => '', 'products' => '', 'sources' => ''], $condition, 'big-spender', $smallSpender);

        // An earlier order within the window counts toward the condition; it is too old to trigger itself.
        $this->postSignedJsonOrder($this->order('big-earlier', 20, 'Bath', 'big@example.com', '-10 days'));
        $this->postSignedJsonOrder($this->order('big-new', 35, 'Bath', 'big@example.com'));
        $this->postSignedJsonOrder($this->order('small-new', 35, 'Bath', 'small@example.com'));

        Assert::assertSame(['big-spender'], $this->tagsOf($bigSpender));
        Assert::assertSame([], $this->tagsOf($smallSpender));
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $orderId, float $total, string $category, string $email, string $placed = 'now'): array
    {
        return [
            'order_id'     => $orderId,
            'order_total'  => $total,
            'order_source' => 'web',
            'order_date'   => (new \DateTimeImmutable($placed))->format(\DATE_ATOM),
            'email'        => $email,
            'items'        => [
                ['item_id' => $orderId.'-sku', 'item_name' => $category.' item', 'qty' => 1, 'category' => $category, 'unit_price' => $total],
            ],
        ];
    }

    private function createContact(string $email): Lead
    {
        $contact = new Lead();
        $contact->setEmail($email);
        $contact->setDateIdentified(new \DateTime());
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * Decision "Places an order" -> optional condition "Purchase history" -> add tag.
     *
     * @param array<string, mixed>      $decisionConfig
     * @param array<string, mixed>|null $conditionConfig
     */
    private function createCampaign(Lead $contact, array $decisionConfig, ?array $conditionConfig, string $tagName, ?Lead $otherContact = null): void
    {
        $tag = new Tag($tagName);
        $this->em->persist($tag);

        $campaign = new Campaign();
        $campaign->setName('Order campaign');
        $campaign->setIsPublished(true);

        $decision = new Event();
        $decision->setOrder(1);
        $decision->setName('Places an order');
        $decision->setType(CampaignSubscriber::DECISION_ORDER_PLACED);
        $decision->setEventType('decision');
        $decision->setProperties($decisionConfig);
        $decision->setCampaign($campaign);

        $parent = $decision;
        $events = [$decision];

        if (null !== $conditionConfig) {
            $condition = new Event();
            $condition->setOrder(2);
            $condition->setName('Purchase history');
            $condition->setType(CampaignSubscriber::CONDITION_PURCHASE_HISTORY);
            $condition->setEventType('condition');
            $condition->setTriggerMode('immediate');
            $condition->setDecisionPath('yes');
            $condition->setParent($decision);
            $condition->setProperties($conditionConfig);
            $condition->setCampaign($campaign);
            $decision->addChild($condition);

            $parent   = $condition;
            $events[] = $condition;
        }

        $addTag = new Event();
        $addTag->setOrder(3);
        $addTag->setName('Add tag');
        $addTag->setType('lead.changetags');
        $addTag->setEventType('action');
        $addTag->setTriggerMode('immediate');
        $addTag->setDecisionPath('yes');
        $addTag->setParent($parent);
        $addTag->setProperties(['add_tags' => [$tagName], 'remove_tags' => []]);
        $addTag->setCampaign($campaign);
        $parent->addChild($addTag);
        $events[] = $addTag;

        $campaign->addEvents($events);
        $this->em->persist($campaign);

        foreach (array_filter([$contact, $otherContact]) as $member) {
            $campaignMember = new CampaignMember();
            $campaignMember->setLead($member);
            $campaignMember->setCampaign($campaign);
            $campaignMember->setDateAdded(new \DateTime('-1 hour'));
            $campaign->addLead(0, $campaignMember);
            $this->em->persist($campaignMember);
        }

        foreach ($events as $event) {
            $this->em->persist($event);
        }

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @return list<string>
     */
    private function tagsOf(Lead $contact): array
    {
        $tags = $this->connection->fetchFirstColumn(
            'SELECT t.tag FROM '.MAUTIC_TABLE_PREFIX.'lead_tags_xref x
             JOIN '.MAUTIC_TABLE_PREFIX.'lead_tags t ON t.id = x.tag_id
             WHERE x.lead_id = ? ORDER BY t.tag',
            [(int) $contact->getId()]
        );

        return array_map('strval', $tags);
    }
}
