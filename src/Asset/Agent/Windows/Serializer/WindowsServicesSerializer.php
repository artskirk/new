<?php

namespace Datto\Asset\Agent\Windows\Serializer;

use Datto\Asset\Agent\Windows\WindowsService;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializes and unserializes WindowsService instances.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class WindowsServicesSerializer implements Serializer
{
    /**
     * @param WindowsService[] $windowsServices
     * @return string[][]
     */
    public function serialize($windowsServices)
    {
        $serializedObjects = [];

        foreach ($windowsServices as $windowsService) {
            $serializedObjects[] = $this->serializeInstance($windowsService);
        }

        return $serializedObjects;
    }

    /**
     * @param string[][] $serializedObjects
     * @return WindowsService[]
     */
    public function unserialize($serializedObjects)
    {
        $windowsServices = [];

        foreach ($serializedObjects as $serializedObject) {
            $windowsServices[] = $this->unserializeInstance($serializedObject);
        }

        return $windowsServices;
    }

    /**
     * @param WindowsService $windowsService
     * @return string[]
     */
    private function serializeInstance(WindowsService $windowsService): array
    {
        $serializedObject['displayName'] = $windowsService->getDisplayName();
        $serializedObject['serviceName'] = $windowsService->getServiceName();

        return $serializedObject;
    }

    /**
     * @param string[] $serializedObject
     * @return WindowsService
     */
    private function unserializeInstance(array $serializedObject): WindowsService
    {
        $displayName = $serializedObject['displayName'] ?? null;
        $serviceName = $serializedObject['serviceName'] ?? null;
        return new WindowsService($displayName, $serviceName);
    }
}
