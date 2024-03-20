<?php
namespace Datto\Upgrade;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Manages the device's upgrade channel
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class ChannelService
{
    const METHOD_GET_CHANNEL = 'v1/device/upgrade/getChannel';
    const METHOD_GET_PARTNER_CHANNELS = 'v1/device/upgrade/listPartnerFacingChannels';
    const METHOD_SET_CHANNEL = 'v1/device/upgrade/setChannel';
    const METHOD_GET_DEFAULT = 'v1/device/upgrade/channel/getDefault';
    const UPGRADE_CHANNEL_KEY = 'upgradeChannels';
    const NO_CHANNEL_SELECTED = 'none';

    /** @var JsonRpcClient */
    private $client;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var UpgradeChannelSerializer */
    private $upgradeChannelSerializer;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param JsonRpcClient|null $client device-web API client
     * @param DeviceConfig|null $deviceConfig
     * @param UpgradeChannelSerializer|null $upgradeChannelSerializer
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        JsonRpcClient $client = null,
        DeviceConfig $deviceConfig = null,
        UpgradeChannelSerializer $upgradeChannelSerializer = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->client = $client ?: new JsonRpcClient();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->upgradeChannelSerializer = $upgradeChannelSerializer ?: new UpgradeChannelSerializer();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * @return Channels Object containing selected & available channel info
     */
    public function getChannels()
    {
        $channels = $this->deviceConfig->getRaw(self::UPGRADE_CHANNEL_KEY);
        if (!is_array(@json_decode($channels, true))) {
            $this->updateCache();
            $channels = $this->deviceConfig->getRaw(self::UPGRADE_CHANNEL_KEY);
        }
        return $this->upgradeChannelSerializer->unserialize($channels);
    }

    /**
     * @param string $channelName Name of the channel to set the device to
     */
    public function setChannel($channelName)
    {
        $this->logger->info('CSV0001 Attempting to set the upgrade channel', ['channelName' => $channelName]);
        $success = $this->client->queryWithId(self::METHOD_SET_CHANNEL, array('channelName' => $channelName));

        if (!$success) {
            $this->logger->error('CSV0002 Could not set upgrade channel', ['channelName' => $channelName]);
            throw new Exception("Could not set upgrade channel to $channelName");
        }

        $this->saveChannelChanges($channelName);
    }

    /**
     * Get default channel.
     */
    public function getDefault()
    {
        try {
            $result = $this->client->queryWithId(self::METHOD_GET_DEFAULT);

            if (!isset($result['channel']['name'])) {
                throw new \Exception('Default channel endpoint returned unexpected result');
            }

            return $result['channel']['name'];
        } catch (\Exception $e) {
            $this->logger->error('CSV0007 Unable to get default channel', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * Update the cache with current channel information
     */
    public function updateCache()
    {
        $this->logger->info('CSV0003 Updating the channel cache for the device');
        try {
            $selected = $this->getSelected();
        } catch (Exception $e) {
            $this->logger->error('CSV0004 There was an error retrieving the current upgrade channel', ['exception' => $e]);
            $selected = static::NO_CHANNEL_SELECTED;
        }
        try {
            $available = $this->getAvailable();
        } catch (Exception $e) {
            $this->logger->error('CSV0005 There was an error retrieving the list of available upgrade channels', ['exception' => $e]);
            $available = null;
        }
        $this->saveChannelChanges($selected, $available);
    }

    /**
     * Get and cache the channels available to partners.
     *
     * @return string[] List of upgrade channels for partners' use
     */
    private function getAvailable()
    {
        $channels = $this->client->queryWithId(self::METHOD_GET_PARTNER_CHANNELS);
        $availableChannels = array();
        foreach ($channels as $channel) {
            $availableChannels[] = $channel['channelName'];
        }
        return $availableChannels;
    }

    /**
     * @return string Device's current upgrade channel
     */
    private function getSelected()
    {
        $channelName = $this->client->queryWithId(self::METHOD_GET_CHANNEL);
        return $channelName;
    }

    /**
     * Set the selected channel and/or available channels in the keyfile
     * @param string $selectedChannel Channel name to set as selected channel
     * @param array|null $availableChannels List of channel names to set as available channels (if null, leave unchanged)
     */
    private function saveChannelChanges($selectedChannel, array $availableChannels = null)
    {
        $this->logger->info('CSV0006 Saving updated channel data.');
        $channelsData = $this->deviceConfig->getRaw(self::UPGRADE_CHANNEL_KEY);
        if ($channelsData) {
            $channels = $this->upgradeChannelSerializer->unserialize($channelsData);
        } else {
            $channels = new Channels('', array());
        }

        $channels->setSelected($selectedChannel);
        if ($availableChannels !== null) {
            $channels->setAvailable($availableChannels);
        }

        $newChannelsData = $this->upgradeChannelSerializer->serialize($channels);
        $this->deviceConfig->set(self::UPGRADE_CHANNEL_KEY, $newChannelsData);
    }
}
