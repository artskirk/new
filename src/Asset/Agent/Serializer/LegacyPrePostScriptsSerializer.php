<?php
namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\PrePostScripts\PrePostScript;
use Datto\Asset\Agent\PrePostScripts\PrePostScriptSettings;
use Datto\Asset\Agent\PrePostScripts\PrePostScriptVolume;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializer for the pre/post scripts keyfile
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class LegacyPrePostScriptsSerializer implements Serializer
{
    /**
     * @param PrePostScriptSettings $prePostScripts script settings to serialize
     * @return array[] serialized volumes (with scripts, block device, etc.)
     */
    public function serialize($prePostScripts)
    {
        $scriptVolumes = $prePostScripts->getVolumes();
        $array = array();
        /** @var PrePostScriptVolume $volume */
        foreach ($scriptVolumes as $volumeName => $volume) {
            $scripts = array();

            /** @var PrePostScript $script */
            foreach ($volume->getScripts() as $script) {
                $scripts[$script->getName()] = array(
                    'scriptname' => $script->getName(),
                    'displayname' => $script->getDisplayName(),
                    'enabled' => $script->isEnabled(),
                    'timeout' => $script->getTimeout()
                );
            }

            $array[$volumeName] = array(
                'blockDevice' => $volume->getBlockDevice(),
                'scripts' => $scripts,
            );
        }

        return array('pps' => serialize($array));
    }

    /**
     * Create an object from the given array.
     *
     * @param mixed $fileArray
     * @return PrePostScriptSettings object built with the array's data
     */
    public function unserialize($fileArray)
    {
        $serializedVolumes = array_key_exists('pps', $fileArray) ? unserialize($fileArray['pps'], ['allowed_classes' => false]) : array();
        $volumes = array();

        if (is_array($serializedVolumes)) {
            foreach ($serializedVolumes as $volumeName => $serializedVolume) {
                $volumes[$volumeName] = new PrePostScriptVolume(
                    $volumeName,
                    $serializedVolume['blockDevice'],
                    $this->unserializeScripts($serializedVolume['scripts'])
                );
            }
        }

        return new PrePostScriptSettings($volumes);
    }

    private function unserializeScripts($serializedScripts): array
    {
        $scripts = array();
        foreach ($serializedScripts as $serializedScript) {
            $scripts[$serializedScript['scriptname']] = new PrePostScript(
                $serializedScript['scriptname'],
                $serializedScript['displayname'],
                (bool)$serializedScript['enabled'],
                intval($serializedScript['timeout'])
            );
        }
        return $scripts;
    }
}
