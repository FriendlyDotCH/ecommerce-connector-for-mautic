<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;
use MauticPlugin\EcommerceConnectorBundle\DependencyInjection\Compiler\OverrideContactColumnsDictionaryPass;
use MauticPlugin\EcommerceConnectorBundle\DependencyInjection\Compiler\OverrideExportHelperPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class EcommerceConnectorBundle extends AbstractPluginBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OverrideContactColumnsDictionaryPass());
        $container->addCompilerPass(new OverrideExportHelperPass());
    }
}
