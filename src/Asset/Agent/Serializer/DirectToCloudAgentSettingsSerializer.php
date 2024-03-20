<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class DirectToCloudAgentSettingsSerializer implements Serializer
{
    const FILE_KEY = 'directToCloudAgentSettings';

    /**
     * {@inheritdoc}
     */
    public function serialize($object)
    {
        /** @var DirectToCloudAgentSettings $object */
        if ($object === null) {
            $serialized = null;
        } else {
            $serialized = json_encode([
                'pendingProtectedSystemAgentConfigRequest' =>
                    $object->getProtectedSystemAgentConfigRequest()
            ]);
        }

        return [
            self::FILE_KEY => $serialized
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serializedObject)
    {
        if (!isset($serializedObject[self::FILE_KEY])) {
            return null;
        }

        $normalized = json_decode($serializedObject[self::FILE_KEY], true);

        if ($normalized === null) {
            return null;
        } else {
            $configuration = $normalized['pendingProtectedSystemAgentConfigRequest'] ?? null;

            return new DirectToCloudAgentSettings($configuration);
        }
    }
}
