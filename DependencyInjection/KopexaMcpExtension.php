<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class KopexaMcpExtension extends Extension
{
    /** @param array<mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        (new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config')))->load('services.php');
    }
}
