<?php

namespace Datto\Log\Processor;

use Datto\Config\AgentConfigFactory;
use Datto\Log\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Updates channel field in log records.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ChannelProcessor implements ProcessorInterface
{
    const DEVICE_KEY = "device-general";

    /** @var AgentConfigFactory */
    protected $agentConfigFactory;

    /** @var string */
    protected $channel;

    public function __construct(AgentConfigFactory $agentConfigFactory)
    {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->channel = static::DEVICE_KEY;
    }

    /**
     * Processes the given record.
     *
     * @param array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        $logRecord = new LogRecord($record);

        // todo: optimize this to not be called with every record
        if ($logRecord->hasAsset()) {
            $this->setToAsset($logRecord->getAsset());
        } else {
            $this->setToDevice();
        }

        $logRecord->setChannel($this->channel);
        return $logRecord->toArray();
    }

    /**
     * Set the channel to the hostname or asset key name.
     * This will update all subsequent log records with the asset channel
     * todo: since some of the this information is static, set it once outside of this method
     *
     * @param string $assetKey
     */
    private function setToAsset(string $assetKey)
    {
        try {
            $config = $this->agentConfigFactory->create($assetKey);

            // If there is an assetKey, it overrides the passed in channel
            if (!empty($assetKey)) {
                $channel = $assetKey;
                if ($assetKey != static::DEVICE_KEY
                    && $config->has("agentInfo")) {
                    $hostname = unserialize($config->get("agentInfo"), ['allowed_classes' => false])["hostname"] ?? '';
                    if (!empty($hostname)) {
                        $channel = $hostname;
                    }
                }

                $this->channel = $channel;
            }
        } catch (Throwable $e) {
            // don't allow exception here to prevent logging
        }
    }

    /**
     * Set the channel to the default device channel name.
     * This will update all subsequent log records with the device channel
     */
    private function setToDevice()
    {
        $this->channel = static::DEVICE_KEY;
    }
}
