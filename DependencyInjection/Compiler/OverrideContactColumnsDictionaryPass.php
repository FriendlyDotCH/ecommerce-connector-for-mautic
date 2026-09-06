<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DependencyInjection\Compiler;

use MauticPlugin\EcommerceConnectorBundle\Integration\Config;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceContactColumnsDictionary;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class OverrideContactColumnsDictionaryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mautic.lead.columns.dictionary')) {
            return;
        }

        $definition = $container->getDefinition('mautic.lead.columns.dictionary');
        $definition->setClass(EcommerceContactColumnsDictionary::class);
        $definition->setAutowired(true);
        $definition->setArgument('$ecommerceConfig', new Reference(Config::class));
    }
}
