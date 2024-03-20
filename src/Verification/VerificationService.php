<?php

namespace Datto\Verification;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Windows\WindowsService;
use Datto\Asset\Agent\Windows\WindowsServiceFactory;
use Datto\Asset\Agent\Windows\WindowsServiceRetriever;
use Datto\Asset\Asset;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\CloudManagedConfig\CloudManagedConfigService;
use Datto\Service\Verification\ScreenshotVerificationDeviceConfig;
use Datto\System\Hardware;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Verification\Application\ApplicationScriptManager;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service class to handle asset verifications.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class VerificationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEFAULT_SCREENSHOT_TIMEOUT_SECONDS = 300;
    const DEFAULT_CLOUD_SCREENSHOT_TIMEOUT_SECONDS = 900;

    const VERIFICATION_SUFFIX = 'verification';

    private Filesystem $filesystem;
    private VerificationQueue $verificationQueue;
    private DateTimeService $dateService;
    private ProcessFactory $processFactory;
    private FeatureService $featureService;
    private AgentService $agentService;
    private WindowsServiceFactory $windowsServiceFactory;
    private WindowsServiceRetriever $windowsServiceRetriever;
    private AgentConfigFactory $agentConfigFactory;
    private Hardware $hardware;
    private DeviceConfig $deviceConfig;
    private InProgressVerificationRepository $inProgressVerificationRepository;
    private ScreenshotVerificationDeviceConfig $screenshotVerificationDeviceConfig;
    private CloudManagedConfigService $cloudManagedConfigService;
    private VerificationCleanupManager $verificationCleanupManager;

    public function __construct(
        Filesystem $filesystem,
        VerificationQueue $verificationQueue,
        DateTimeService $dateService,
        ProcessFactory $processFactory,
        FeatureService $featureService,
        AgentService $agentService,
        WindowsServiceFactory $windowsServiceFactory,
        WindowsServiceRetriever $windowsServiceRetriever,
        AgentConfigFactory $agentConfigFactory,
        Hardware $hardware,
        DeviceConfig $deviceConfig,
        InProgressVerificationRepository $inProgressVerificationRepository,
        ScreenshotVerificationDeviceConfig $screenshotVerificationDeviceConfig,
        CloudManagedConfigService $cloudManagedConfigService,
        VerificationCleanupManager $verificationCleanupManager = null
    ) {
        $this->filesystem = $filesystem;
        $this->verificationQueue = $verificationQueue;
        $this->dateService = $dateService;
        $this->processFactory = $processFactory;
        $this->featureService = $featureService;
        $this->agentService = $agentService;
        $this->windowsServiceFactory = $windowsServiceFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->hardware = $hardware;
        $this->windowsServiceRetriever = $windowsServiceRetriever;
        $this->deviceConfig = $deviceConfig;
        $this->inProgressVerificationRepository = $inProgressVerificationRepository;
        $this->screenshotVerificationDeviceConfig = $screenshotVerificationDeviceConfig;
        $this->cloudManagedConfigService = $cloudManagedConfigService;
        $this->verificationCleanupManager = $verificationCleanupManager ?? new VerificationCleanupManager(); // web/lib
    }

    public function setScreenshotsEnabled(bool $enabled): void
    {
        $this->screenshotVerificationDeviceConfig->setEnabled($enabled);

        if ($this->featureService->isSupported(FeatureService::FEATURE_CLOUD_MANAGED_CONFIGS)) {
            $this->logger->info('SER0003 Syncing local config to cloud');
            $this->cloudManagedConfigService->pushToCloud();
        } else {
            $this->logger->info('SER0004 Cloud managed config feature not supported, skipping.');
        }
    }

    public function isScreenshotsEnabled(): bool
    {
        return $this->screenshotVerificationDeviceConfig->isEnabled();
    }

    /**
     * Set override values for CPU core count and amount of ram to use for verifications.
     *
     * @param string $agentKey
     * @param int $overrideCpuCores
     * @param int $overrideRamInMiB
     */
    public function setScreenshotOverride(string $agentKey, int $overrideCpuCores, int $overrideRamInMiB): void
    {
        $cpuCores = $this->hardware->getCpuCores();
        $ramInMiB = $this->hardware->getPhysicalRamMiB();

        if ($overrideCpuCores > $cpuCores) {
            throw new \InvalidArgumentException("You must specify an amount less than or equal to the total number of system CPU cores. ($cpuCores cores total)");
        }

        if ($overrideRamInMiB > $ramInMiB) {
            throw new \InvalidArgumentException("You must specify an amount less than or equal to the total system memory. ($ramInMiB bytes total)");
        }

        $screenshotOverride = new ScreenshotOverride($overrideCpuCores, $overrideRamInMiB);

        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->saveRecord($screenshotOverride);
    }

    /**
     * @param string $agentKey
     */
    public function clearScreenshotOverride(string $agentKey): void
    {
        $screenshotOverride = new ScreenshotOverride();

        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->clearRecord($screenshotOverride);
    }

    /**
     * Get override values for CPU core count and amount of ram to use for verifications.
     *
     * @param string $agentKey
     * @return ScreenshotOverride
     */
    public function getScreenshotOverride(string $agentKey): ScreenshotOverride
    {
        $screenshotOverride = new ScreenshotOverride();

        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->loadRecord($screenshotOverride);

        return $screenshotOverride;
    }

    /**
     * Set the expected applications for an asset.
     *
     * @param string $agentKey
     * @param string[] $applicationIds
     */
    public function setExpectedApplications(string $agentKey, array $applicationIds): void
    {
        $agent = $this->agentService->get($agentKey);

        $this->featureService->assertSupported(FeatureService::FEATURE_APPLICATION_VERIFICATIONS, null, $agent);

        $this->logger->setAssetContext($agentKey);
        $this->logger->info('SER0001 Setting applications for application verification', ['applicationIds' => $applicationIds]); // log code is used by device-web see DWI-2252

        $agent->getScreenshotVerification()
            ->setExpectedApplications($applicationIds);

        $this->agentService->save($agent);
    }

    /**
     * Set the expected services for an asset.
     *
     * @param string $agentKey
     * @param string[] $serviceIds
     */
    public function setExpectedServices(string $agentKey, array $serviceIds): void
    {
        $agent = $this->agentService->get($agentKey);

        $this->featureService->assertSupported(FeatureService::FEATURE_APPLICATION_VERIFICATIONS, null, $agent);

        $this->logger->setAssetContext($agentKey);
        $this->logger->info('SER0002 Setting services for service verification', ['serviceIds' => $serviceIds]); // log code is used by device-web see DWI-2252

        $agent->getScreenshotVerification()
            ->setExpectedServices($serviceIds);

        $this->agentService->save($agent);
    }

    /**
     * Get the expected applications for an asset.
     *
     * @param string $agentKey
     * @return string[]
     */
    public function getExpectedApplications(string $agentKey): array
    {
        $agent = $this->agentService->get($agentKey);

        return $agent->getScreenshotVerification()->getExpectedApplications();
    }

    /**
     * Determines if a backup is required before the running service list can
     * be obtained.
     *
     * @param string $agentKey
     * @return bool
     */
    public function isBackupRequired(string $agentKey): bool
    {
        return $this->windowsServiceRetriever->isBackupRequired($agentKey);
    }

    /**
     * Get the expected services for an asset.
     *
     * @param string $agentKey
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function getExpectedServices(string $agentKey): array
    {
        $agent = $this->agentService->get($agentKey);

        $expectedServiceIds = $agent->getScreenshotVerification()->getExpectedServices();
        $availableServiceObjects = $this->windowsServiceRetriever->getCachedRunningServices($agent->getKeyName());

        $expectedServiceObjects = [];
        foreach ($expectedServiceIds as $serviceId) {
            if (array_key_exists($serviceId, $availableServiceObjects)) {
                $service = $availableServiceObjects[$serviceId];
            } else {
                $service = $this->windowsServiceFactory->createFromServiceId($serviceId, $agent);
            }
            $expectedServiceObjects[$service->getId()] = $service;
        }

        return $expectedServiceObjects;
    }

    /**
     * Gets the list of services on the device that aren't already expected.
     *
     * @param string $agentKey
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function getNotExpectedServices(string $agentKey): array
    {
        $availableServiceObjects = $this->windowsServiceRetriever->getCachedRunningServices($agentKey);
        $expectedServiceObjects = $this->getExpectedServices($agentKey);

        return array_diff_key($availableServiceObjects, $expectedServiceObjects);
    }

    /**
     * Queue up an asset for verifications.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     */
    public function queue(Asset $asset, int $snapshotEpoch): void
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_VERIFICATIONS, null, $asset);
        $this->assertSnapshotExists($asset, $snapshotEpoch);

        $mostRecentPointWithScreenshot = $asset->getLocal()->getRecoveryPoints()->getMostRecentPointWithScreenshot();
        $mostRecentVerificationEpoch = $mostRecentPointWithScreenshot ? $mostRecentPointWithScreenshot->getEpoch() : 0;

        $this->verificationQueue->add(new VerificationAsset(
            $asset->getKeyName(),
            $snapshotEpoch,
            $this->dateService->getTime(),
            $mostRecentVerificationEpoch
        ));
    }

    /**
     * Immediately run verifications in the background for a given assets snapshots.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     */
    public function runInBackground(Asset $asset, int $snapshotEpoch): void
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_VERIFICATIONS, null, $asset);
        $this->assertSnapshotExists($asset, $snapshotEpoch);

        $process = $this->processFactory->get([
                'snapctl',
                'asset:verification:run',
                $asset->getKeyName(),
                $snapshotEpoch,
                '--background'
            ]);

        $process->mustRun();
    }

    /**
     * Retrieve information for queued verifications.
     *
     * @param Asset|null $asset
     * @param int|null $snapshotEpoch
     * @return VerificationAsset[]
     */
    public function getQueuedVerifications(Asset $asset = null, int $snapshotEpoch = null): array
    {
        $verifications = $this->verificationQueue->getQueue();

        if ($asset) {
            $filter = function (VerificationAsset $verificationAsset) use ($asset): bool {
                return $verificationAsset->getAssetName() === $asset->getKeyName();
            };

            $verifications = array_filter($verifications, $filter);
        }

        if ($snapshotEpoch) {
            $filter = function (VerificationAsset $verificationAsset) use ($snapshotEpoch): bool {
                return $verificationAsset->getSnapshotTime() === $snapshotEpoch;
            };

            $verifications = array_filter($verifications, $filter);
        }

        return array_values($verifications);
    }

    /**
     * Check if there is a running verification.
     *
     * @param string|null $assetKey If provided, check if a verification is running for a specific asset.
     * @return bool
     */
    public function hasInProgressVerification(string $assetKey = null): bool
    {
        return (bool)$this->findInProgressVerification($assetKey);
    }

    /**
     * Find an in progress verification if one exists. Returns null if one does not.
     *
     * @param string|null $assetKey
     * @return InProgressVerification|null
     */
    public function findInProgressVerification(string $assetKey = null): ?InProgressVerification
    {
        if ($assetKey) {
            $inProgress = $this->inProgressVerificationRepository->findByAssetKey($assetKey);
        } else {
            $inProgress = $this->inProgressVerificationRepository->find();
        }

        return $inProgress;
    }

    /**
     * Get the screenshot verification timeout.
     *
     * @return int
     */
    public function getScreenshotTimeout(): int
    {
        $timeout = $this->deviceConfig->get(DeviceConfig::KEY_SCREENSHOT_TIMEOUT, null);

        if ($timeout === null) {
            $role = $this->deviceConfig->getRole();

            if (in_array($role, [DeviceRole::AZURE, DeviceRole::CLOUD])) {
                $timeout = self::DEFAULT_CLOUD_SCREENSHOT_TIMEOUT_SECONDS;
            } else {
                $timeout = self::DEFAULT_SCREENSHOT_TIMEOUT_SECONDS;
            }
        }

        return $timeout;
    }

    /**
     * Determine the number of seconds that we should use for Lakitu's script timeout. The amount of time here
     * will include waits for Application Verification, Service Verification, and Custom scripts.
     *
     * @param Agent $agent
     * @return int
     */
    public function getScriptsTimeout(Agent $agent): int
    {
        $screenshotVerification = $agent->getScreenshotVerification();

        $expectedApplicationCount = count($screenshotVerification->getExpectedApplications());
        $timeout = $expectedApplicationCount * ApplicationScriptManager::APPLICATION_SCRIPT_TIMEOUT_SECONDS;

        if ($screenshotVerification->hasExpectedServices()) {
            $timeout += ApplicationScriptManager::SERVICES_ENUMERATION_SCRIPT_TIMEOUT_SECONDS;
        }

        if ($agent->getScriptSettings() && !empty($agent->getScriptSettings()->getScripts())) {
            $timeout += VerificationFactory::SCRIPTS_TIMEOUT_IN_SECS;
        }

        return $timeout;
    }

    /**
     * Remove verification from queue
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     */
    public function remove(Asset $asset, int $snapshotEpoch): void
    {
        $this->verificationQueue->remove(new VerificationAsset(
            $asset->getKeyName(),
            $snapshotEpoch,
            $this->dateService->getTime()
        ));
    }

    /**
     * Clears the verification queue
     */
    public function removeAll(): void
    {
        $this->verificationQueue->removeAll();
    }

    /**
     * Cancel a running verification if there is one running for an asset.
     *
     * @param Asset $asset
     */
    public function cancel(Asset $asset): void
    {
        $this->verificationCleanupManager->cleanupVerifications($asset->getKeyName());
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     */
    private function assertSnapshotExists(Asset $asset, int $snapshotEpoch): void
    {
        $local = $asset->getLocal();
        $exists = $local->getRecoveryPoints()->exists($snapshotEpoch);

        if (!$exists) {
            throw new Exception('Snapshot does not exist locally: ' . $snapshotEpoch);
        }
    }
}
