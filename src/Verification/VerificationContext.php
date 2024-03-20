<?php

namespace Datto\Verification;

use Datto\Asset\Agent\Agent;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Connection\ConnectionInterface;
use Datto\Restore\CloneSpec;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Virtualization\VirtualMachine;

/**
 * This class holds the context that is used by the verification stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class VerificationContext
{
    /** @var string|null Identifier for this verification run */
    private ?string $runIdentifier = null;

    /** @var Agent The agent being verified */
    private Agent $agent;

    /** @var ConnectionInterface The connection information for this screenshot */
    private ConnectionInterface $connection;

    /** @var int Epoch time of the snapshot */
    private int $snapshotEpoch;

    /** @var int|null Timeout (seconds) used by LakituReady and TakeScreenshot */
    private ?int $readyTimeout = null;

    /** @var int|null User-configurable delay (seconds) to wait before taking a screenshot */
    private ?int $screenshotWaitTime = null;

    /** @var int|null Timeout (seconds) used by RunScripts */
    private ?int $scriptsTimeout = null;

    /**
     * @var bool
     * Whether or not Lakitu was successfully injected.
     * Set during PrepareVm if Lakitu was successfully injected.
     * Used by AssetReady and RunScripts to determine if Lakitu can be queried.
     */
    private bool $lakituInjected = false;

    private bool $lakituResponded = false;
    private ?string $lakituVersion = null;
    private ScreenshotOverride $screenshotOverride;

    private ?VirtualMachine $virtualMachine = null;
    private CloneSpec $cloneSpec;
    private RecoveryPoint $recoveryPoint;
    private bool $cloudResourceReleaseRequired = false;
    
    /** @var bool Set if pending reboot is detected during HIR */
    private bool $rebootPending = false;

    /** @var bool Set if failure state is detected in the screenshot  */
    private bool $screenshotFailed = false;

    public function __construct(
        string $runIdentifier,
        Agent $agent,
        ConnectionInterface $connection,
        int $snapshotEpoch,
        int $readyTimeout,
        int $screenshotWaitTime,
        int $scriptsTimeout,
        ScreenshotOverride $screenshotOverride,
        RecoveryPoint $recoveryPoint
    ) {
        $this->runIdentifier = $runIdentifier;
        $this->agent = $agent;
        $this->connection = $connection;
        $this->snapshotEpoch = $snapshotEpoch;
        $this->readyTimeout = $readyTimeout;
        $this->screenshotWaitTime = $screenshotWaitTime;
        $this->scriptsTimeout = $scriptsTimeout;
        $this->screenshotOverride = $screenshotOverride;
        $this->recoveryPoint = $recoveryPoint;
        $this->cloneSpec = CloneSpec::fromAsset($agent, $snapshotEpoch, 'verification', false);
    }

    public function getRunIdentifier(): string
    {
        return (string) $this->runIdentifier;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    /**
     * @return ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return int Epoch time of the snapshot
     */
    public function getSnapshotEpoch()
    {
        return $this->snapshotEpoch;
    }

    /**
     * @return VirtualMachine the virtual machine instance used for the verification process
     */
    public function getVirtualMachine(): VirtualMachine
    {
        if ($this->virtualMachine === null) {
            throw new \RuntimeException("Expected VirtualMachine to be instantiated.");
        }

        return $this->virtualMachine;
    }

    /**
     * @param VirtualMachine|null $vm
     */
    public function setVirtualMachine(?VirtualMachine $vm)
    {
        $this->virtualMachine = $vm;
    }

    public function getReadyTimeout(): int
    {
        return (int) $this->readyTimeout;
    }

    /**
     * @param int $readyTimeout
     */
    public function setReadyTimeout($readyTimeout)
    {
        $this->readyTimeout = $readyTimeout;
    }

    public function getScreenshotWaitTime(): int
    {
        return (int) $this->screenshotWaitTime;
    }

    public function getScriptsTimeout(): int
    {
        return (int) $this->scriptsTimeout;
    }

    /**
     * @return bool
     */
    public function isLakituInjected()
    {
        return $this->lakituInjected;
    }

    /**
     * @param bool $lakituInjected
     */
    public function setLakituInjected($lakituInjected)
    {
        $this->lakituInjected = $lakituInjected;
    }

    public function hasLakituResponded(): bool
    {
        return $this->lakituResponded;
    }

    public function setLakituResponded()
    {
        $this->lakituResponded = true;
    }

    /**
     * @return string|null
     */
    public function getLakituVersion()
    {
        return $this->lakituVersion;
    }

    public function setLakituVersion(string $lakituVersion = null)
    {
        $this->lakituVersion = $lakituVersion;
    }

    public function setScreenshotFailed(): void
    {
        $this->screenshotFailed = true;
    }

    public function hasScreenshotFailed(): bool
    {
        return $this->screenshotFailed;
    }

    /**
     * Return the full path of the screenshot without any extension
     *
     * @return string Full path of the screenshot
     */
    public function getScreenshotPath(): string
    {
        return ScreenshotFileRepository::getScreenshotPath(
            $this->agent->getKeyName(),
            $this->snapshotEpoch
        );
    }

    /**
     * Return the full path of the screenshot image, including extension
     *
     * @return string Full path of the screenshot
     */
    public function getScreenshotImagePath(): string
    {
        return ScreenshotFileRepository::getScreenshotImagePath(
            $this->agent->getKeyName(),
            $this->snapshotEpoch
        );
    }

    /**
     * Return the full path of the screenshot error text, including extension
     *
     * @return string Full path of the screenshot
     */
    public function getScreenshotErrorTextPath(): string
    {
        return ScreenshotFileRepository::getScreenshotErrorTextPath(
            $this->agent->getKeyName(),
            $this->snapshotEpoch
        );
    }

    /**
     * Return the full path of the OS update pending file, including extension
     *
     * @return string Full path of the file
     */
    public function getOsUpdatePendingPath(): string
    {
        return ScreenshotFileRepository::getOsUpdatePendingPath(
            $this->agent->getKeyName(),
            $this->snapshotEpoch
        );
    }

    /**
     * @return ScreenshotOverride
     */
    public function getScreenshotOverride(): ScreenshotOverride
    {
        return $this->screenshotOverride;
    }

    /**
     * @param ScreenshotOverride $screenshotOverride
     */
    public function setScreenshotOverride(ScreenshotOverride $screenshotOverride)
    {
        $this->screenshotOverride = $screenshotOverride;
    }

    /**
     * @return CloneSpec
     */
    public function getCloneSpec(): CloneSpec
    {
        return $this->cloneSpec;
    }

    /**
     * @return bool of whether windows update is pending on the given recovery
     * point; returns 'false' as fallback for prior recovery points
     */
    public function getOsUpdatePending(): bool
    {
        return $this->recoveryPoint->wasOsUpdatePending() ?? false;
    }

    /**
     * If a cloud hypervisor connection was procured from device-web,
     * then we need to inform device-web that we're not using it
     * anymore when we're done with it. This is to ensure that
     * resource balancing works properly.
     */
    public function isCloudResourceReleaseRequired(): bool
    {
        return $this->cloudResourceReleaseRequired;
    }

    public function setCloudResourceReleaseRequired(bool $value)
    {
        $this->cloudResourceReleaseRequired = $value;
    }
    
    public function setOsUpdatePending(bool $updatePending)
    {
        $this->recoveryPoint->setOsUpdatePending($updatePending);
    }
}
