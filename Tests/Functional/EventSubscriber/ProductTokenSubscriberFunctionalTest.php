<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderItemPayload;
use MauticPlugin\EcommerceConnectorBundle\DTO\OrderPayload;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceProductRepository;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceOrderTracker;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\ConfiguresEcommerceIntegration;
use MauticPlugin\EcommerceConnectorBundle\Tests\Functional\InstallsEcommerceSchema;
use PHPUnit\Framework\Assert;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProductTokenSubscriberFunctionalTest extends MauticMysqlTestCase
{
    use ConfiguresEcommerceIntegration;
    use InstallsEcommerceSchema;

    protected $useCleanupRollback = false;

    private Lead $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installEcommerceSchema($this->connection);
        $this->configureIntegration();

        $products = static::getContainer()->get(EcommerceProductRepository::class);
        $catalog  = [
            ['155', 'Lavender Candle', 'Candles', '20', 'instock'],
            ['156', 'Cedar Candle', 'Candles', '22', 'instock'],
            ['157', 'Rose Candle', 'Candles', '18', 'outofstock'],
            ['160', 'Reed Diffuser', 'Diffusers', '30', 'instock'],
            ['170', 'Hidden <Draft>', 'Candles', '10', 'instock'],
        ];
        foreach ($catalog as [$id, $name, $category, $price, $stock]) {
            $products->upsertProduct('woocommerce', [
                'external_id' => $id, 'name' => $name, 'category' => $category, 'categories' => [$category], 'price' => $price, 'currency' => 'CHF',
                'stock_status' => $stock, 'url' => 'https://shop.example/p/'.$id, 'image_url' => 'https://shop.example/i/'.$id.'.jpg',
                'status' => '170' === $id ? 'draft' : 'publish',
            ]);
        }

        $this->buyer = $this->createContact('buyer@example.com');
        $other       = $this->createContact('other@example.com');

        // Best sellers: Cedar (5), Rose (4, out of stock), Diffuser (3), Lavender (2), Draft (9, hidden).
        $this->order('T-1', $other, [['156', 5], ['157', 4], ['160', 3], ['170', 9]]);
        // The buyer bought Lavender: Candles is their category.
        $this->order('T-2', $this->buyer, [['155', 2]]);
    }

    public function testBlocksRenderPerSourceAndLinksAreTracked(): void
    {
        $html = $this->send(
            '<div>{ecommerce_products source=&quot;recommended&quot; limit=&quot;2&quot;}</div>'
            .'<div id="best">{ecommerce_products source="best_sellers" limit="5"}</div>'
            .'<div id="bought">{ecommerce_products source="last_purchased"}</div>'
            .'<div id="viewed">{ecommerce_products source="viewed"}</div>',
            $this->buyer
        );

        // Recommended: best candle they have not bought, then overall best sellers.
        Assert::assertMatchesRegularExpression('/Cedar Candle.*Reed Diffuser/s', $this->section($html, '<div>', '<div id="best">'));
        Assert::assertStringNotContainsString('Lavender', $this->section($html, '<div>', '<div id="best">'));

        $best = $this->section($html, '<div id="best">', '<div id="bought">');
        Assert::assertMatchesRegularExpression('/Cedar Candle.*Reed Diffuser.*Lavender Candle/s', $best);
        Assert::assertStringNotContainsString('Rose Candle', $best, 'Out of stock is not offered.');
        Assert::assertStringNotContainsString('Draft', $best, 'Unpublished products are not offered.');

        Assert::assertStringContainsString('Lavender Candle', $this->section($html, '<div id="bought">', '<div id="viewed">'));
        Assert::assertStringNotContainsString('<table', $this->section($html, '<div id="viewed">', null), 'Nothing viewed: empty.');

        // Product links went through Mautic's link tracking.
        Assert::assertStringNotContainsString('https://shop.example/p/', $html);
        Assert::assertMatchesRegularExpression('#href="[^"]*/r/[^"]+"#', $html);
        Assert::assertStringContainsString('src="https://shop.example/i/156.jpg"', $html, 'Images are not redirected.');
    }

    public function testWithoutContactOnlyBestSellersAndCategoryFilter(): void
    {
        $html = $this->send('{ecommerce_products source="last_purchased"}|{ecommerce_products source="recommended" limit="1" category="Diffusers"}', null);

        [$bought, $recommended] = explode('|', $html);
        Assert::assertSame('', $bought);
        Assert::assertStringContainsString('Reed Diffuser', $recommended);
        Assert::assertStringNotContainsString('Candle', $recommended);
    }

    public function testPlainTextBlock(): void
    {
        $event = new EmailSendEvent(null, [
            'content'   => '<p>x</p>',
            'plainText' => "Top picks:\n{ecommerce_products source=\"best_sellers\" limit=\"1\"}",
            'lead'      => ['id' => $this->buyer->getId()],
        ]);
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($event, EmailEvents::EMAIL_ON_DISPLAY);

        // Price in the configured money locale; the link is tracked in plain text too.
        Assert::assertMatchesRegularExpression('/^Top picks:\n- Cedar Candle \([^)]*22[.,]00[^)]*\): \{trackable=\w+\}$/u', $event->getPlainText());
    }

    private function send(string $content, ?Lead $lead): string
    {
        $email = new Email();
        $email->setName('Products');
        $email->setSubject('Products');
        $email->setCustomHtml($content);
        $this->em->persist($email);
        $this->em->flush();

        $event = new EmailSendEvent(null, [
            'content'      => $content,
            'email'        => $email,
            'idHash'       => 'abc123def456',
            'lead'         => null !== $lead ? ['id' => $lead->getId(), 'email' => $lead->getEmail()] : ['id' => null],
            'source'       => null,
            'tokens'       => [],
        ]);
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($event, EmailEvents::EMAIL_ON_SEND);

        // What MailHelper does with the tokens.
        return str_replace(array_keys($event->getTokens()), array_values($event->getTokens()), $event->getContent());
    }

    private function section(string $html, string $from, ?string $to): string
    {
        $start = strpos($html, $from);
        Assert::assertNotFalse($start);

        return null === $to ? substr($html, $start) : substr($html, $start, (int) strpos($html, $to) - $start);
    }

    /**
     * @param list<array{0: string, 1: int}> $lines product ID and quantity
     */
    private function order(string $orderId, Lead $lead, array $lines): void
    {
        static::getContainer()->get(EcommerceOrderTracker::class)->recordOrder(new OrderPayload($orderId, '100', 'woocommerce', 'CHF', null, null, null, array_map(
            static fn (array $line): OrderItemPayload => new OrderItemPayload('SKU-'.$line[0], 'Line '.$line[0], $line[1], null, '10', 0, $line[0]),
            $lines
        ), new \DateTimeImmutable('-2 days')), $this->em->getRepository(Lead::class)->find($lead->getId()));
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        static::getContainer()->get('mautic.lead.model.lead')->saveEntity($lead);

        return $lead;
    }
}
