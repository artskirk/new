<?php
namespace Datto\App\Controller\Api\V1\Device\Power;

use Datto\System\PowerManager;
use Datto\System\RebootException;
use Datto\Common\Utility\Filesystem;
use DateTime;

/**
 * API endpoint to schedule/cancel reboot.
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
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class Reboot
{
    /* @var PowerManager */
    private $powerManager;

    /** @var  Filesystem */
    private $filesystem;

    public function __construct(
        PowerManager $powerManager,
        Filesystem $filesystem
    ) {
        $this->powerManager = $powerManager;
        $this->filesystem = $filesystem;
    }

    /**
     * Schedules a reboot.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_POWER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_POWER_MANAGEMENT")
     * @param string $dateTimeStr Datetime string at which reboot needs to occur.
     * @return bool Returns true if set, false otherwise
     */
    public function schedule($dateTimeStr)
    {
        try {
            $dateTime = DateTime::createFromFormat("Y/m/d g:i A", $dateTimeStr);
            $this->powerManager->setRebootDateTime($dateTime->getTimestamp());
            $result = true;
        } catch (RebootException $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * Immediately reboots a device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_POWER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_POWER_MANAGEMENT")
     */
    public function now(): void
    {
        $this->powerManager->rebootNow();
    }

    /**
     * Returns the reboot schedule (UTC timestamp) if reboot is already scheduled, false otherwise.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_POWER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_POWER_MANAGEMENT")
     * @return string|bool Returns date if reboot is already scheduled, false otherwise.
     */
    public function getSchedule()
    {
        $config = $this->powerManager->getRebootSchedule();

        if ($config) {
            $result = date("Y/m/d g:i A", $config->getRebootAt());
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * Cancels a scheduled reboot.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_POWER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_POWER_MANAGEMENT")
     * @return bool
     */
    public function cancel(): bool
    {
        $this->powerManager->cancel();

        return $this->powerManager->getRebootSchedule() == null;
    }
}
