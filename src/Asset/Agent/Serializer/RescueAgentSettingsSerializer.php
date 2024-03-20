<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\RescueAgentSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializes rescue agent settings.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class RescueAgentSettingsSerializer implements Serializer
{
    const FILE_KEY = 'rescueAgentSettings';
    const SOURCE_AGENT_KEYNAME = 'sourceAgentKeyName';
    const SOURCE_AGENT_SNAPSHOT_EPOCH = 'sourceAgentSnapshotEpoch';

    /**
     * @param RescueAgentSettings $rescueAgentSettings
     *
     * @return array
     */
    public function serialize($rescueAgentSettings)
    {
        $serializedSettings = array(self::FILE_KEY => null);
        if ($rescueAgentSettings) {
            $serializedSettings[self::FILE_KEY] =
                json_encode(array(
                    self::SOURCE_AGENT_KEYNAME => $rescueAgentSettings->getSourceAgentKeyName(),
                    self::SOURCE_AGENT_SNAPSHOT_EPOCH => $rescueAgentSettings->getSourceAgentSnapshotEpoch()
                ));
        }
        return $serializedSettings;
    }

    /**
     * @param array $fileArray
     *
     * @return RescueAgentSettings|null
     */
    public function unserialize($fileArray)
    {
        if (isset($fileArray[self::FILE_KEY])) {
            $settingsArray = json_decode($fileArray[self::FILE_KEY], true);
            $settingsArrayIsValid = isset($settingsArray[self::SOURCE_AGENT_KEYNAME])
                && isset($settingsArray[self::SOURCE_AGENT_SNAPSHOT_EPOCH]);
            if ($settingsArrayIsValid) {
                return new RescueAgentSettings(
                    $settingsArray[self::SOURCE_AGENT_KEYNAME],
                    $settingsArray[self::SOURCE_AGENT_SNAPSHOT_EPOCH]
                );
            }
        }
        return null;
    }
}
