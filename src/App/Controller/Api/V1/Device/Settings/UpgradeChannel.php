<?php

namespace Datto\App\Controller\Api\V1\Device\Settings;

use Datto\Upgrade\ChannelService;

/**
 * API endpoint to query and change maintenance mode settings.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Peter Salu <psalu@datto.com>
 */
class UpgradeChannel
{
    /** @var ChannelService */
    private $channelService;

    public function __construct(ChannelService $channelService)
    {
        $this->channelService = $channelService;
    }

    /**
     * API call to set the device update channel
     *
     * FIXME We should combine v1/device/settings/{upgradeChannel,updateWindow} and v1/device/system/upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_UPGRADES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_UPGRADES")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "channel" = {
     *         @Symfony\Component\Validator\Constraints\NotBlank()
     *     }
     * })
     * @param string $channel name of the channel to set
     */
    public function setChannel($channel): void
    {
        $this->channelService->setChannel($channel);
    }

    /**
     * API call to retrieve the currently selected device update channel
     *
     * FIXME We should combine v1/device/settings/{upgradeChannel,updateWindow} and v1/device/system/upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_UPGRADES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_UPGRADES")
     * @return array
     */
    public function getChannel()
    {
        $this->channelService->updateCache();
        $channels = $this->channelService->getChannels();
        $selectedChannel = $channels->getSelected();
        return ['channel' => $selectedChannel];
    }
}
