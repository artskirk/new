<?php
namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\PrePostScripts\PrePostScript;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializer for the list of scripts from the agent API (Datto\Asset\Agent\BaseAgentApi)
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class AgentApiPrePostScriptsSerializer implements Serializer
{
    /**
     * @param PrePostScript[] $scripts
     * @return array[] script array serialized in the format of the API
     */
    public function serialize($scripts)
    {
        $serializedScripts = array();
        foreach ($scripts as $scriptName => $script) {
            $serializedScripts[$script->getName()] = array(
                'scriptname' => $script->getName(),
                'timeout' => $script->getTimeout()
            );
        }
        return $serializedScripts;
    }

    /**
     * @param array[] $serializedScripts array of script arrays from the API
     * @return PrePostScript[] unserialized scripts
     */
    public function unserialize($serializedScripts)
    {
        $scripts = array();
        foreach ($serializedScripts as $serializedScript) {
            $scripts[$serializedScript['scriptname']] = new PrePostScript(
                $serializedScript['scriptname'],
                $serializedScript['displayname'],
                false,
                0
            );
        }
        return $scripts;
    }
}
