<?php

namespace Datto\App\Container;

use Psr\Container\ContainerInterface;

/**
 * This class is used by the ServiceCollectionCompilerPass to allow the container
 * to inject multiple service instances into a constructor.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ServiceCollection
{
    /** @var ContainerInterface */
    private $serviceLocator;
    /** @var array */
    private $serviceIds;

    /**
     * @param ContainerInterface $serviceLocator
     * @param array $serviceIds
     */
    public function __construct(ContainerInterface $serviceLocator, array $serviceIds = [])
    {
        $this->serviceLocator = $serviceLocator;
        $this->serviceIds = $serviceIds;
    }

    /**
     * Get all the service instances in this collection
     *
     * @return array
     */
    public function getAll(): array
    {
        $services = [];
        foreach ($this->serviceIds as $serviceId) {
            $services[] = $this->serviceLocator->get($serviceId);
        }

        return $services;
    }
}
