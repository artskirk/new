<?php

namespace Datto\App\Controller\Api\V1\Device\Roundtrip;

use Datto\Roundtrip\RoundtripManager;

/**
 * API for Usb roundtrip.
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
 * @author Stephen Allan <sallan@datto.com>
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class Usb extends AbstractRoundtrip
{
    /* @var RoundtripManager */
    private $roundtripManager;

    public function __construct(RoundtripManager $roundtripManager)
    {
        $this->roundtripManager = $roundtripManager;
    }

    /**
     * Start a USB roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agents" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "shares" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "enclosures" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "confirmationEmail" = @Symfony\Component\Validator\Constraints\Type(type="string"),
     *   "encrypt" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "inhibitBackups" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "cloudSync" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     *
     * @param array $agents Snapshots of agents to sync. See below for expected format
     * @param array $shares Snapshots of shares to sync. See below for expected format
     * @param string[] $enclosures Devices to create the roundtrip pool on
     * @param string $confirmationEmail Address to send emails to on completion
     * @param bool $encrypt True to encrypt the roundtrip
     * @param bool $inhibitBackups True to stop backups occurring during the roundtrip process
     * @param bool $cloudSync Mark volumes for offsite syncing via speedsync
     * @return bool True if successful. An exception will occur on failure
     *
     * Expected array format:
     * "agents|shares" => [
     *     "<uuid>" => [                    // Indexes 'from' and 'to' can optionally be used to define a snapshot range.
     *         "from" => "<snapshotEpoch>", // Both must exist or be missing. One cannot be defined without the other.
     *         "to" => "<snapshotEpoch>"    // If both are missing or NULL then all snapshots are synced.
     *     ],
     *     ...
     * ]
     */
    public function start(
        array $agents,
        array $shares,
        array $enclosures,
        string $confirmationEmail,
        bool $encrypt,
        bool $inhibitBackups,
        bool $cloudSync
    ): bool {
        $this->roundtripManager->startUsb(
            $agents,
            $shares,
            $enclosures,
            $confirmationEmail,
            $encrypt,
            $inhibitBackups,
            $cloudSync
        );

        return true; // All endpoints must return a value
    }

    /**
     * Get the status of a USB Roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return array USB Roundtrip status data.
     */
    public function getStatus(): array
    {
        return $this->statusToArray($this->roundtripManager->getUsbStatus());
    }

    /**
     * Cancel a running USB roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return bool True if successful. An exception will occur on failure
     */
    public function cancel(): bool
    {
        $this->roundtripManager->cancelUsb();

        return true; // All endpoints must return a value
    }

    /**
     * Determine if any enclosures are connected to the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return bool True if the device has at least one available enclosure, False otherwise
     */
    public function hasEnclosures(): bool
    {
        return !empty($this->roundtripManager->getEnclosures());
    }
}
