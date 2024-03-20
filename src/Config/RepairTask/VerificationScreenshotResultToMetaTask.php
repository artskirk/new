<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\VerificationScreenshotResult;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceState;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Log\DeviceLoggerInterface;

/**
 * Go through all screenshot files and update <asset>.recoveryPointsMeta with the
 * verification result information.
 */
class VerificationScreenshotResultToMetaTask implements ConfigRepairTaskInterface
{
    private AgentService $agentService;
    private DeviceState $deviceState;
    private Filesystem $filesystem;
    private DeviceLoggerInterface $logger;

    public function __construct(
        AgentService $agentService,
        DeviceState $deviceState,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem = null
    ) {
        $this->agentService = $agentService;
        $this->deviceState = $deviceState;
        $this->logger = $logger;
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        // we really don't want to run this over and over again
        if ($this->deviceState->has(DeviceState::SCREENSHOT_RESULTS_MIGRATED)) {
            $this->logger->info('CFG0021 This repair task has already been successfully run');

            return false;
        }

        $agents = $this->agentService->getAll();

        foreach ($agents as $agent) {
            $recoveryPoints = $agent->getLocal()->getRecoveryPoints()->getAll();

            foreach ($recoveryPoints as $recoveryPoint) {
                $verificationScreenshotResult = $this->getVerificationScreenshotResult(
                    $agent->getKeyName(),
                    $recoveryPoint->getEpoch()
                );

                if ($verificationScreenshotResult !== null) {
                    $recoveryPoint->setVerificationScreenshotResult($verificationScreenshotResult);
                }
            }

            $this->logger->setAssetContext($agent->getKeyName());
            $this->logger->info("CFG0020 Saving verification screenshot info for agent");
            $this->agentService->save($agent);
        }

        $this->deviceState->set(DeviceState::SCREENSHOT_RESULTS_MIGRATED, true);
        return true;
    }

    /**
     * Returns VerificationScreenshotResult object if one should be made
     *
     * @param string $assetKey
     * @param int $snapshotEpoch
     *
     * @return VerificationScreenshotResult|null
     */
    private function getVerificationScreenshotResult(string $assetKey, int $snapshotEpoch)
    {
        $screenshotPath = ScreenshotFileRepository::getScreenshotImagePath($assetKey, $snapshotEpoch);
        $errorTextPath = ScreenshotFileRepository::getScreenshotErrorTextPath($assetKey, $snapshotEpoch);
        $osUpdatePendingPath = ScreenshotFileRepository::getOsUpdatePendingPath($assetKey, $snapshotEpoch);

        $screenshotExists =  $this->filesystem->exists($screenshotPath);
        $errorTextExists = $this->filesystem->exists($errorTextPath);
        $osUpdatePending = $this->filesystem->exists($osUpdatePendingPath);

        if (!$screenshotExists && !$errorTextExists && !$osUpdatePending) {
            return null;
        }

        return new VerificationScreenshotResult(
            $screenshotExists,
            $osUpdatePending,
            $errorTextExists ? $this->filesystem->fileGetContents($errorTextPath) : null
        );
    }
}
