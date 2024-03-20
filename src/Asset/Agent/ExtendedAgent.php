<?php

namespace Datto\Asset\Agent;

use Datto\Log\AssetRecord;

/**
 * A bunch of additional fields that are not part of the asset/agent structure and must be fetched through some other
 * means.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ExtendedAgent
{
    /** @var bool */
    private $agentless;

    /** @var bool */
    private $alertsSuppressed;

    /** @var bool */
    private $hasScripts;

    /** @var string */
    private $ipAddress;

    /** @var bool */
    private $archived;

    /** @var bool */
    private $removing;

    /** @var int */
    private $lastBackup;

    /** @var int */
    private $lastScreenshot;

    /** @var AssetRecord[] */
    private $logs;

    /** @var bool */
    private $isLocked;

    /** @var float */
    private $protectedSize;

    /** @var array */
    private $protectedVolumes;

    /** @var bool `true` if the last verification captured a screenshot of a successfully booted OS */
    private $screenshotSuccess;

    /** @var bool */
    private $scriptFailure;

    /** @var bool */
    private $showScreenshots;

    /** @var bool */
    private $osUpdatePending;

    /** @var array */
    private $diskDrives;

    /**
     * @param bool $agentless
     * @param bool $alertsSuppressed
     * @param bool $hasScripts
     * @param string $ipAddress
     * @param bool $isArchived
     * @param bool $isRemoving
     * @param int $lastBackup
     * @param int $lastScreenshot
     * @param AssetRecord[] $logs
     * @param bool $isLocked
     * @param float $protected
     * @param array $protectedVolumes
     * @param bool $screenshotSuccess
     * @param bool $scriptFailure
     * @param bool $showScreenshots
     * @param bool $osUpdatePending
     * @param array $diskDrives
     */
    public function __construct(
        bool $agentless,
        bool $alertsSuppressed,
        bool $hasScripts,
        string $ipAddress,
        bool $isArchived,
        bool $isRemoving,
        int $lastBackup,
        int $lastScreenshot,
        array $logs,
        bool $isLocked,
        float $protected,
        array $protectedVolumes,
        bool $screenshotSuccess,
        bool $scriptFailure,
        bool $showScreenshots,
        bool $osUpdatePending,
        array $diskDrives
    ) {
        $this->agentless = $agentless;
        $this->alertsSuppressed = $alertsSuppressed;
        $this->hasScripts = $hasScripts;
        $this->ipAddress = $ipAddress;
        $this->archived = $isArchived;
        $this->removing = $isRemoving;
        $this->lastBackup = $lastBackup;
        $this->lastScreenshot = $lastScreenshot;
        $this->logs = $logs;
        $this->isLocked = $isLocked;
        $this->protectedSize = $protected;
        $this->protectedVolumes = $protectedVolumes;
        $this->screenshotSuccess = $screenshotSuccess;
        $this->scriptFailure = $scriptFailure;
        $this->showScreenshots = $showScreenshots;
        $this->osUpdatePending = $osUpdatePending;
        $this->diskDrives = $diskDrives;
    }

    public function isAgentless(): bool
    {
        return $this->agentless;
    }

    public function isAlertsSuppressed(): bool
    {
        return $this->alertsSuppressed;
    }

    public function isHasScripts(): bool
    {
        return $this->hasScripts;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function isRemoving(): bool
    {
        return $this->removing;
    }

    public function getLastBackup(): int
    {
        return $this->lastBackup;
    }

    public function getLastScreenshot(): int
    {
        return $this->lastScreenshot;
    }

    /** @return AssetRecord[] */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function getProtectedSize(): float
    {
        return $this->protectedSize;
    }

    public function getProtectedVolumes(): array
    {
        return $this->protectedVolumes;
    }

    public function isScreenshotSuccess(): bool
    {
        return $this->screenshotSuccess;
    }

    public function isScriptFailure(): bool
    {
        return $this->scriptFailure;
    }

    public function isShowScreenshots(): bool
    {
        return $this->showScreenshots;
    }

    public function isOsUpdatePending(): bool
    {
        return $this->osUpdatePending;
    }

    public function getDiskDrives(): array
    {
        return $this->diskDrives ?? [];
    }
}
