<?php

namespace Datto\Upgrade;

use Datto\Asset\Serializer\Serializer;

/**
 * @author Peter Salu <psalu@datto.com>
 */
class UpgradeChannelSerializer implements Serializer
{
    const SELECTED_KEY = 'selected';
    const AVAILABLE_KEY = 'available';

    /**
     * {@inheritdoc}
     */
    public function serialize($object)
    {
        $channelsArray = array(
            self::SELECTED_KEY => $object->getSelected(),
            self::AVAILABLE_KEY => $object->getAvailable()
        );

        return json_encode($channelsArray);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serializedObject)
    {
        $channelsArray = json_decode($serializedObject, true);
        $channels = new Channels($channelsArray[self::SELECTED_KEY] ?? '', $channelsArray[self::AVAILABLE_KEY] ?? []);

        return $channels;
    }
}
