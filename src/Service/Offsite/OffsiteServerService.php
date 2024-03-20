<?php

namespace Datto\Service\Offsite;

use Datto\Config\LocalConfig;

/**
 * This class handles off-site server information.
 *
 * @author Alex Mankowski <amankowski@datto.com>
 */
class OffsiteServerService
{
    /** @var LocalConfig */
    private $localConfig;

    /**
     * @param LocalConfig $localConfig
     */
    public function __construct(
        LocalConfig $localConfig
    ) {
        $this->localConfig = $localConfig;
    }

    /**
     * Gets the off-site server IP address for the device.
     *
     * @return string Returns IP address of the off-site server.
     */
    public function getServerAddress()
    {
        return $this->localConfig->get('serverAddress');
    }
}
