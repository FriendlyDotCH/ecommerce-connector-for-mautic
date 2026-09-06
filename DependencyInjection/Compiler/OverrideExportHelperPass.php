<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\DependencyInjection\Compiler;

use Mautic\CoreBundle\Helper\ExportHelper;
use MauticPlugin\EcommerceConnectorBundle\Service\EcommerceExportHelper;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OverrideExportHelperPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ExportHelper::class)) {
            return;
        }

        $definition = $container->findDefinition(ExportHelper::class);
        $definition->setClass(EcommerceExportHelper::class);
        $definition->setAutowired(true);
    }
}
