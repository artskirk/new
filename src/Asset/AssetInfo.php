<?php

namespace Datto\Asset;

use Datto\Verification\Notification\VerificationResults;
use Exception;

/**
 * This class holds attributes for volume update
 *
 * @author John Fury Christ <jchrist@datto.com>
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AssetInfo
{
    const REQUIRED_FIELDS = ['name', 'zfsPath', 'type', 'originDeviceID'];

    /** @var int */
    private $originDeviceID;

    /** @var string */
    private $name;

    /** @var string */
    private $zfsPath;

    /** @var string */
    private $type;

    /** @var string */
    private $os;

    /** @var string|null */
    private $hostname;

    /** @var int */
    private $agentUsedSpace;

    /** @var int */
    private $agentFreeSpace;

    /** @var int */
    private $agentSize;

    /** @var int|null */
    private $used;

    /** @var int|null */
    private $usedBySnap;

    /** @var float|null */
    private $compressRatio;

    /** @var int|null */
    private $snapCount;

    /** @var int|null */
    private $firstSnap;

    /** @var int|null */
    private $lastSnap;

    /** @var string */
    private $agentType;

    /** @var string */
    private $version;

    /** @var string */
    private $serial;

    /** @var int|null */
    private $errorTime;

    /** @var string */
    private $lastError;

    /** @var int */
    private $screenshotProcessSuccess;

    /**
     * @var string|null Error message from the take screenshot step during verification
     *
     * `VerificationResults::SCREENSHOT_PROCESS_SUCCESS` if no errors occurred.
     */
    private $screenshotProcessError;

    /** @var int */
    private $screenshotTime;

    /** @var int */
    private $screenshotSuccess;

    /**
     * @var string|null Results from the screenshot analysis
     *
     * `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if no failure states were detected.
     */
    private $screenshotFailText;

    /** @var string|null */
    private $displayName;

    /** @var string|null */
    private $uuid;

    /** @var string|null */
    private $fqdn;

    /** @var string|null */
    private $localIP;

    /** @var bool */
    private $paused;

    /** @var bool */
    private $archived;

    /** @var bool */
    private $needsReboot;

    /** @var bool */
    private $wantsReboot;

    /** @var array|null */
    private $verificationInfo;

    /** @var string|null */
    private $backupState;

    /** @var int|null */
    private $lastCheckin;

    /** @var int|null */
    private $pauseUntil;

    /** @var bool */
    private $pauseWhileMetered;

    /** @var int|null */
    private $maxBandwidthInBits;

    /** @var int|null */
    private $maxThrottledBandwidthInBits;

    /** @var int|null */
    private $lastVolumeValidationCheck;

    /** @var bool|null */
    private $lastVolumeValidationResult;

    /** @var bool */
    private $isMigrationInProgress;

    /**
     * @param array $assetInfo
     */
    public function __construct(array $assetInfo)
    {
        $missingRequired = array_diff(self::REQUIRED_FIELDS, array_keys($assetInfo));
        if (count($missingRequired) > 0) {
            throw new Exception('Missing required keys to construct AssetInfo: ' . implode(',', $missingRequired));
        }

        // required
        $this->name = $assetInfo['name'];
        $this->zfsPath = $assetInfo['zfsPath'];
        $this->type = $assetInfo['type'];
        $this->originDeviceID = $assetInfo['originDeviceID'];

        // needs default values for compatibility
        $this->os = $assetInfo['os'] ?? '';
        $this->agentUsedSpace = $assetInfo['agentUsedSpace'] ?? 0;
        $this->agentFreeSpace = $assetInfo['agentFreeSpace'] ?? 0;
        $this->agentSize = $assetInfo['agentSize'] ?? 0;
        $this->agentType = $assetInfo['agentType'] ?? '';
        $this->version = $assetInfo['version'] ?? '';
        $this->serial = $assetInfo['serial'] ?? '';
        $this->lastError = $assetInfo['lastError'] ?? '';
        $this->screenshotProcessSuccess = $assetInfo['screenshotProcessSuccess'] ?? 0;
        $this->screenshotProcessError = $assetInfo['screenshotProcessError'] ?? VerificationResults::SCREENSHOT_PROCESS_SUCCESS;
        $this->screenshotTime = $assetInfo['screenshotTime'] ?? 0;
        $this->screenshotSuccess = $assetInfo['screenshotSuccess'] ?? 0;
        $this->screenshotFailText = $assetInfo['screenshotFailText'] ?? VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;

        // optional
        $this->hostname = $assetInfo['hostname'] ?? null;
        $this->used = $assetInfo['used'] ?? null;
        $this->usedBySnap = $assetInfo['usedBySnap'] ?? null;
        $this->compressRatio = $assetInfo['compressRatio'] ?? null;
        $this->snapCount = $assetInfo['snapCount'] ?? null;
        $this->firstSnap = $assetInfo['firstSnap'] ?? null;
        $this->lastSnap = $assetInfo['lastSnap'] ?? null;
        $this->errorTime = $assetInfo['errorTime'] ?? null;
        $this->displayName = $assetInfo['displayName'] ?? null;
        $this->uuid = $assetInfo['uuid'] ?? null;
        $this->fqdn = $assetInfo['fqdn'] ?? null;
        $this->localIP = $assetInfo['localIP'] ?? null;
        $this->verificationInfo = $assetInfo['verificationInfo'] ?? null;
        $this->backupState = $assetInfo['backupState'] ?? null;
        $this->lastCheckin = $assetInfo['lastCheckin'] ?? null;
        $this->pauseUntil = $assetInfo['pauseUntil'] ?? null;
        $this->pauseWhileMetered = $assetInfo['pauseWhileMetered'] ?? false;
        $this->maxBandwidthInBits = $assetInfo['maxBandwidthInBits'] ?? null;
        $this->maxThrottledBandwidthInBits = $assetInfo['maxThrottledBandwidthInBits'] ?? null;
        $this->lastVolumeValidationCheck = $assetInfo['lastVolumeValidationCheck'] ?? null;
        $this->lastVolumeValidationResult = $assetInfo['lastVolumeValidationResult'] ?? null;

        // Casting to bool in case value doesn't exist. We don't want null.
        $this->paused = (bool) ($assetInfo['paused'] ?? false);
        $this->archived = (bool) ($assetInfo['archived'] ?? false);
        $this->isMigrationInProgress = (bool) ($assetInfo['isMigrationInProgress'] ?? false);

        $this->needsReboot = (bool) ($assetInfo['needsReboot'] ?? false);
        $this->wantsReboot = (bool) ($assetInfo['wantsReboot'] ?? false);
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return get_object_vars($this);
    }

    /**
     * @return int
     */
    public function getOriginDeviceID(): int
    {
        return $this->originDeviceID;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getZfsPath()
    {
        return $this->zfsPath;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getOs()
    {
        return $this->os;
    }

    /**
     * @return string|null
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * @return int
     */
    public function getAgentUsedSpace()
    {
        return $this->agentUsedSpace;
    }

    /**
     * @return int
     */
    public function getAgentFreeSpace()
    {
        return $this->agentFreeSpace;
    }

    /**
     * @return int
     */
    public function getAgentSize()
    {
        return $this->agentSize;
    }

    /**
     * @return int|null
     */
    public function getUsed()
    {
        return $this->used;
    }

    /**
     * @return int|null
     */
    public function getUsedBySnap()
    {
        return $this->usedBySnap;
    }

    /**
     * @return float|null
     */
    public function getCompressRatio()
    {
        return $this->compressRatio;
    }

    /**
     * @return int|null
     */
    public function getSnapCount()
    {
        return $this->snapCount;
    }

    /**
     * @return int|null
     */
    public function getFirstSnap()
    {
        return $this->firstSnap;
    }

    /**
     * @return int|null
     */
    public function getLastSnap()
    {
        return $this->lastSnap;
    }

    /**
     * @return string
     */
    public function getAgentType()
    {
        return $this->agentType;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getSerial()
    {
        return $this->serial;
    }

    /**
     * @return int|null
     */
    public function getErrorTime()
    {
        return $this->errorTime;
    }

    /**
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * @return int
     */
    public function getScreenshotProcessSuccess()
    {
        return $this->screenshotProcessSuccess;
    }

    /**
     * @return string|null
     */
    public function getScreenshotProcessError()
    {
        return $this->screenshotProcessError;
    }

    /**
     * @return int
     */
    public function getScreenshotTime()
    {
        return $this->screenshotTime;
    }

    /**
     * @return int
     */
    public function getScreenshotSuccess()
    {
        return $this->screenshotSuccess;
    }

    /**
     * @return string|null
     */
    public function getScreenshotFailText()
    {
        return $this->screenshotFailText;
    }

    /**
     * @return string|null
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * @return string|null
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * @return string|null
     */
    public function getFqdn()
    {
        return $this->fqdn;
    }

    /**
     * @return null|string
     */
    public function getLocalIP()
    {
        return $this->localIP;
    }

    /**
     * @return bool
     */
    public function getPaused()
    {
        return $this->paused;
    }

    /**
     * @return bool
     */
    public function getArchived()
    {
        return $this->archived;
    }

    /**
     * @return bool
     */
    public function needsReboot()
    {
        return $this->needsReboot;
    }

    public function isMigrationInProgress(): bool
    {
        return $this->isMigrationInProgress;
    }
}
