<?php

namespace Datto\JsonRpc\Routing;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pass class names of all tagged JSON RPC controllers to the JsonRpcRouteLoaderService
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class JsonRpcRouteCompilerPass implements CompilerPassInterface
{
    const TAG = 'controller.jsonrpc';

    /**
     * Pass controller names to route loader service
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $loaderDefinition = $container->findDefinition(JsonRpcRouteLoaderService::class);

        // find all tagged JSON RPC controllers
        $taggedServices = $container->findTaggedServiceIds(self::TAG);

        // pass the class names to the loader service
        $controllers = array_keys($taggedServices);
        $loaderDefinition->addMethodCall('addControllers', [$controllers]);
    }
}
