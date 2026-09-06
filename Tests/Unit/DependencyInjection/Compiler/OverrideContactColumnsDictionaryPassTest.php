<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\DependencyInjection\Compiler;

use Mautic\LeadBundle\Services\ContactColumnsDictionary;
use MauticPlugin\EcommerceConnectorBundle\DependencyInjection\Compiler\OverrideContactColumnsDictionaryPass;
use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceContactColumnsDictionary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class OverrideContactColumnsDictionaryPassTest extends TestCase
{
    public function testBindsEcommerceConfigByNameInsteadOfPrependingIt(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(ContactColumnsDictionary::class);
        $definition->setAutowired(true);
        $container->setDefinition('mautic.lead.columns.dictionary', $definition);

        (new OverrideContactColumnsDictionaryPass())->process($container);

        $processed = $container->getDefinition('mautic.lead.columns.dictionary');
        $this->assertSame(EcommerceContactColumnsDictionary::class, $processed->getClass());
        $this->assertTrue($processed->isAutowired());

        $arguments = $processed->getArguments();
        $this->assertArrayNotHasKey(0, $arguments);

        $configArgument = $processed->getArgument('$ecommerceConfig');
        $this->assertInstanceOf(Reference::class, $configArgument);
        $this->assertSame(Config::class, (string) $configArgument);
    }
}
