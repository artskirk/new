<?php

namespace Datto\Asset\Agent\PrePostScripts;

use Datto\Asset\Agent\Serializer\AgentApiPrePostScriptsSerializer;
use Datto\Asset\Agent\Volume;
use Datto\Asset\Agent\Volumes;
use Exception;

/**
 * PrePostScript settings of an agent
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class PrePostScriptSettings
{
    /** @var PrePostScriptVolume[] $volumes */
    private $volumes;

    /** @var AgentApiPrePostScriptsSerializer $apiSerializer */
    private $apiSerializer;

    /**
     * @param PrePostScriptVolume[] $volumes array of volumes (each containing its' scripts)
     * @param AgentApiPrePostScriptsSerializer $apiSerializer serializer for converting settings to agent APi format
     */
    public function __construct(array $volumes = array(), AgentApiPrePostScriptsSerializer $apiSerializer = null)
    {
        $this->volumes = $volumes;
        $this->apiSerializer = $apiSerializer ?: new AgentApiPrePostScriptsSerializer();
    }

    /**
     * Convert the settings to the format that the agent API uses
     *
     * @param string $volumeGuid volume guid to get script list from
     * @return array[] array of enabled scripts in the agent API's format
     */
    public function getApiFormattedScripts($volumeGuid)
    {
        $this->verifyVolumeExists($volumeGuid);
        return $this->apiSerializer->serialize($this->volumes[$volumeGuid]->getEnabledScripts());
    }

    /**
     * Get the volumes
     *
     * @return PrePostScriptVolume[] array of volumes with PrePostScripts
     */
    public function getVolumes()
    {
        return $this->volumes;
    }

    /**
     * Enable a script on a volume
     *
     * @param string $volume volume to enable the script on
     * @param string $script script to enable
     */
    public function enableScript($volume, $script): void
    {
        $this->verifyVolumeExists($volume);
        $this->volumes[$volume]->enableScript($script);
    }

    /**
     * Disable a script on a volume
     *
     * @param string $volume volume to disable the script on
     * @param string $script script to disable
     */
    public function disableScript($volume, $script): void
    {
        $this->verifyVolumeExists($volume);
        $this->volumes[$volume]->disableScript($script);
    }

    /**
     * Refresh the pre post script settings with information from elsewhere
     *
     * @param PrePostScript[] $apiScripts array of scripts, typically recieved from the agent API
     * @param Volumes $volumes list of the agent's volumes
     */
    public function refresh(array $apiScripts, Volumes $volumes): void
    {
        foreach ($volumes as $volume) {
            $volumeName = $volume->getGuid();
            $blockDevice = $volume->getBlockDevice();
            $newVolume = new PrePostScriptVolume($volumeName, $blockDevice, $apiScripts);
            if (array_key_exists($volumeName, $this->volumes)) {
                $this->volumes[$volumeName]->copyFrom($newVolume);
            } else {
                $this->volumes[$volumeName] = $newVolume;
            }
        }
    }

    private function verifyVolumeExists($volumeName): void
    {
        if (!array_key_exists($volumeName, $this->volumes)) {
            throw new Exception('This volume does not exist in the pre post scripts settings');
        }
    }
}
