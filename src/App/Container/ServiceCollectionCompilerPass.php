<?php

namespace Datto\App\Container;

use Exception;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class ServiceCollectionCompilerPass implements CompilerPassInterface
{
    const TAGPREFIX = 'collection';

    /**
     * You can modify the container here before it is dumped to PHP code.
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        // find any collection.* tags which exist in the yml
        $collectionTags = array_filter($container->findTags(), function ($item) {
            return strpos($item, self::TAGPREFIX) === 0;
        });

        foreach ($collectionTags as $tag) {
            try {
                // for this tag, create array of service references and service ids
                $taggedServices = $container->findTaggedServiceIds($tag);
                $serviceIds = array_keys($taggedServices);
                $serviceRefs = [];
                foreach ($serviceIds as $serviceId) {
                    $serviceRefs[$serviceId] = new Reference($serviceId);
                }

                // register a new ServiceCollection instance in the container for this tag
                $container
                    ->register($tag, ServiceCollection::class)
                    ->setPublic(true)
                    ->setArguments([ServiceLocatorTagPass::register($container, $serviceRefs), $serviceIds]);
            } catch (Throwable $e) {
                throw new Exception("Error creating ServiceCollection from tag '$tag'", 0, $e);
            }
        }
    }
}
