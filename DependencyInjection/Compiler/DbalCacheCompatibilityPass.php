<?php

namespace Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\Psr6\CacheAdapter;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function assert;
use function in_array;
use function is_a;
use function trigger_deprecation;

/** @internal  */
final class DbalCacheCompatibilityPass implements CompilerPassInterface
{
    public const CONFIGURATION_TAG          = 'doctrine.connections';
    private const CACHE_METHODS_PSR6_SUPPORT = [
        'setResultCache',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds(self::CONFIGURATION_TAG)) as $id) {
            foreach ($container->getDefinition($id)->getMethodCalls() as $methodCall) {
                if (! in_array($methodCall[0], self::CACHE_METHODS_PSR6_SUPPORT, true)) {
                    continue;
                }

                $aliasId      = (string) $methodCall[1][0];
                $definitionId = (string) $container->getAlias($aliasId);

                $this->wrapIfNecessary($container, $aliasId, $definitionId, true);
            }
        }
    }

    private function createCompatibilityLayerDefinition(ContainerBuilder $container, string $definitionId, bool $shouldBePsr6): ?Definition
    {
        $definition = $container->getDefinition($definitionId);

        while (! $definition->getClass() && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        if ($shouldBePsr6 === is_a($definition->getClass(), CacheItemPoolInterface::class, true)) {
            return null;
        }

        $targetClass   = CacheProvider::class;
        $targetFactory = DoctrineProvider::class;

        if ($shouldBePsr6) {
            $targetClass   = CacheItemPoolInterface::class;
            $targetFactory = CacheAdapter::class;

            trigger_deprecation(
                'doctrine/doctrine-bundle',
                '2.4',
                'Configuring doctrine/cache is deprecated. Please update the cache service "%s" to use a PSR-6 cache.',
                $definitionId
            );
        }

        return (new Definition($targetClass))
            ->setFactory([$targetFactory, 'wrap'])
            ->addArgument(new Reference($definitionId));
    }

    private function wrapIfNecessary(ContainerBuilder $container, string $aliasId, string $definitionId, bool $shouldBePsr6): void
    {
        $compatibilityLayer = $this->createCompatibilityLayerDefinition($container, $definitionId, $shouldBePsr6);
        if ($compatibilityLayer === null) {
            return;
        }

        $compatibilityLayerId = $definitionId . '.compatibility_layer';
        $container->setAlias($aliasId, $compatibilityLayerId);
        $container->setDefinition($compatibilityLayerId, $compatibilityLayer);
    }
}
