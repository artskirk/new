<?php

namespace Datto\Configuration;

use Datto\Cloud\JsonRpcClient;

/**
 * Gets and sets settings for this device on the webserver.
 */
class RemoteSettings
{
    /** @var JsonRpcClient */
    private $client;

    /**
     * @param JsonRpcClient|null $client
     */
    public function __construct(JsonRpcClient $client = null)
    {
        $this->client = $client ?: new JsonRpcClient();
    }

    /**
     * Sets the offsite sync speed on record for this device in the database.
     * @param int $speed
     */
    public function setOffsiteSyncSpeed($speed)
    {
        $this->client->notifyWithId('v1/device/speedTest/setSpeed', array(
            'speed' => $speed
        ));
    }
}
