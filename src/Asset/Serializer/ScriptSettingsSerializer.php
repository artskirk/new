<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\ScriptSettings;
use Datto\Asset\VerificationScript;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;

/**
 * Class ScriptSettingsSerializer
 *
 * Unserialize:
 *  $scriptSettings = $serializer->unserialize(array(
 *      ScriptSettingsSerializer::SCRIPTS => $scriptFilePathsArray
 *  ));
 *
 * Serialize:
 *  $serializedScriptSettings = $serializer->serialize(new ScriptSettings(...));
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScriptSettingsSerializer implements Serializer
{
    const FILE_KEY = 'scriptSettings';
    const SCRIPTS = 'scripts';

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(DeviceLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param ScriptSettings $verificationSettings
     * @return array
     */
    public function serialize($verificationSettings)
    {
        return array(static::FILE_KEY =>
            json_encode(array(
                static::SCRIPTS => $verificationSettings->getScriptFilePaths())));
    }

    /**
     * @param mixed $fileArray
     * @return ScriptSettings
     */
    public function unserialize($fileArray)
    {
        $name = $this->getAssetName($fileArray);
        $keyName = $this->getKeyName($fileArray);
        if (isset($fileArray[static::FILE_KEY])) {
            $settings = @json_decode($fileArray[static::FILE_KEY], true);
            $scripts = isset($settings[static::SCRIPTS]) ? $this->createScriptsFromAssociativeArray($settings[static::SCRIPTS], $keyName) : [];
            $verificationSettings = new ScriptSettings($name, $scripts);
        } else {
            $verificationSettings = new ScriptSettings($name);
        }
        return $verificationSettings;
    }

    /**
     * Get the agent info name from file array
     *
     * @param $fileArray
     * @return mixed
     */
    private function getAssetName($fileArray)
    {
        $agentInfoString = $fileArray['agentInfo'];
        $agentInfo = unserialize($agentInfoString, ['allowed_classes' => false]);
        return $agentInfo['name'];
    }

    /**
     * Get the keyname
     *
     * @param $fileArray
     * @return mixed
     */
    private function getKeyName($fileArray)
    {
        return $fileArray['keyName'];
    }

    /**
     * Turns [ [myScriptName] => '/datto/config/keys/scripts/myAsset/01_abc123_myScript' ]
     * into
     * new VerificationScript("myScript", "abc123")
     *
     * @param $array
     * @param $keyName
     * @return VerificationScript[]
     */
    private function createScriptsFromAssociativeArray($array, $keyName)
    {
        $scriptsObjectArray = array();
        foreach ($array as $path => $name) {
            //The following grabs the uniqid from the script path, e.g.
            // /datto/config/keys/scripts/my_Asset/01_abc123_my_Script
            //  uniqid = abc123
            //  tier = 01
            $filename = basename($path);
            $parts = explode('_', $filename);

            if (count($parts) < 3) {
                $logger = $this->logger ?: LoggerFactory::getAssetLogger($keyName);
                $logger->warning('SVR0003 Unable to read verification script', ['filename' => $filename]);
                continue;
            } else {
                $tier = $parts[0];
                $uniqid = $parts[1];
                $scriptsObjectArray[] = new VerificationScript($name, $uniqid, $tier);
            }
        }

        return $scriptsObjectArray;
    }
}
