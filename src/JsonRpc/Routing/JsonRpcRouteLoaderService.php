<?php

namespace Datto\JsonRpc\Routing;

use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Custom RouteLoader which creates routes for all public methods on JSON RPC controller classes tagged in the container
 * (see JsonRpcRouteCompilerPass)
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class JsonRpcRouteLoaderService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const API_NAMESPACE_START = 'datto\app\controller';

    /** @var int */
    private $defaultRouteIndex;

    /** @var array */
    private $controllerNames;

    public function __construct()
    {
        $this->defaultRouteIndex = 0;
        $this->controllerNames = [];
    }

    /**
     * Add a JSON RPC controller class to the loader service
     *
     * @param array $controllerNames class names of a JSON RPC controllers
     */
    public function addControllers(array $controllerNames)
    {
        $this->controllerNames = $controllerNames;
    }

    /**
     * Generate a RouteCollection from the known JSON RPC controller classes
     *
     * @return RouteCollection
     */
    public function load(): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($this->controllerNames as $class) {
            $class = new \ReflectionClass($class);
            if (!$class->isAbstract()) {
                foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $isFromBaseClass = $method->getDeclaringClass()->getName() !== $class->getName();
                    $isFromTrait = in_array($method->getName(), $this->getTraitMethodNames($class));
                    $excludeFromEndpoint = $isFromBaseClass || $isFromTrait || $method->isConstructor();

                    if ($excludeFromEndpoint) {
                        continue;
                    }

                    $this->defaultRouteIndex = 0;
                    $this->addRoute($collection, $class, $method);

                    // This is inefficient and ugly, but it's only run during the container generation,
                    // i.e. when `appctl cache:clear` is called.

                    $hasSecurityAnnotations = preg_match('/RequiresFeature/', $method->getDocComment())
                        && preg_match('/RequiresPermission/', $method->getDocComment());

                    if (!$hasSecurityAnnotations) {
                        $this->logger->warning('RPC0001 Method is missing @RequiresFeature or @RequiresPermission annotation.', ['method' => $class->getName() . '::' . $method->getName()]);
                    }
                }
            }
        }

        return $collection;
    }

    /**
     * Add a route for the class/method to the route collection
     *
     * @param RouteCollection $routeCollection
     * @param ReflectionClass $class
     * @param ReflectionMethod $method
     */
    private function addRoute(RouteCollection $routeCollection, ReflectionClass $class, ReflectionMethod $method)
    {
        $name = $this->getDefaultRouteName($class, $method);
        $classNameParts = explode('\\', str_replace(self::API_NAMESPACE_START, '', strtolower($class->getName())));
        $path = implode('/', array_slice($classNameParts, 1)) . '/' . strtolower($method->getName());
        $route = new Route($path);
        $route->setDefault('_controller', $class->getName() . '::' . $method->getName());
        $route->setMethods(["POST"]);
        $route->setCondition("request.attributes.has('_jsonrpc_method')");
        $routeCollection->add($name, $route);
    }

    /**
     * Gets the default route name for a class method.
     *
     * @param \ReflectionClass  $class
     * @param \ReflectionMethod $method
     *
     * @return string
     */
    private function getDefaultRouteName(\ReflectionClass $class, \ReflectionMethod $method)
    {
        $name = strtolower(str_replace('\\', '_', $class->name) . '_' . $method->name);
        if ($this->defaultRouteIndex > 0) {
            $name .= '_' . $this->defaultRouteIndex;
        }
        ++$this->defaultRouteIndex;

        return preg_replace(array(
            '/(bundle|controller)_/',
            '/action(_\d+)?$/',
            '/__/',
        ), array(
            '_',
            '\\1',
            '_',
        ), $name);
    }

    private function getTraitMethodNames(ReflectionClass $class): array
    {
        $traitMethodNames = [];
        foreach ($class->getTraits() as $trait) {
            foreach ($trait->getMethods() as $traitMethod) {
                $traitMethodNames[] = $traitMethod->getName();
            }
        }

        return $traitMethodNames;
    }
}
