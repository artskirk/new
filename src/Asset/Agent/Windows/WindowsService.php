<?php

namespace Datto\Asset\Agent\Windows;

use Exception;

/**
 * Holds information about a windows service.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class WindowsService
{
    /** @var null|string */
    private $displayName;

    /** @var null|string */
    private $serviceName;

    /**
     * @param null|string $displayName
     * @param null|string $serviceName
     */
    public function __construct(string $displayName = null, string $serviceName = null)
    {
        if (empty($displayName) && empty($serviceName)) {
            throw new Exception('A WindowsService must have a displayName and/or a serviceName (both cannot be blank)');
        }
        $this->displayName = $displayName;
        $this->serviceName = $serviceName;
    }

    /**
     * @return null|string
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * @return null|string
     */
    public function getServiceName()
    {
        return $this->serviceName;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->serviceName ?? $this->displayName;
    }
}
