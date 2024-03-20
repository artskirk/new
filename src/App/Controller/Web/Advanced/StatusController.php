<?php

namespace Datto\App\Controller\Web\Advanced;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\OrphanDatasetService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Datto\Service\Storage\DriveHealthService;
use Datto\Service\Networking\NetworkService;
use Datto\System\Hardware;
use Datto\System\Migration\AbstractMigration;
use Datto\System\Migration\MigrationService;
use Datto\System\Migration\ZpoolReplace\ZpoolReplaceMigration;
use Datto\System\ResourceMonitor;
use Datto\System\Storage\StorageService;
use Datto\Utility\Uptime;
use Datto\Verification\VerificationQueue;
use Datto\Verification\VerificationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the device advanced status page.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class StatusController extends AbstractBaseController
{
    private DeviceConfig $deviceConfig;
    private VerificationQueue $verificationQueue;
    private AgentService $agentService;
    private StorageService $storageService;
    private MigrationService $migrationService;
    private Hardware $hardware;
    private ResourceMonitor $resourceMonitor;
    private OrphanDatasetService $orphanDatasetService;
    private VerificationService $verificationService;
    private DateTimeService $dateTimeService;
    private Uptime $uptime;
    private DriveHealthService $driveHealthService;
    private Serial $deviceSerial;

    public function __construct(
        NetworkService $networkService,
        DeviceConfig $deviceConfig,
        VerificationQueue $verificationQueue,
        AgentService $agentService,
        StorageService $storageService,
        MigrationService $migrationService,
        Hardware $hardware,
        ResourceMonitor $resourceMonitor,
        OrphanDatasetService $orphanDatasetService,
        VerificationService $verificationService,
        DateTimeService $dateTimeService,
        Uptime $uptime,
        DriveHealthService $driveHealthService,
        Serial $deviceSerial,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->deviceConfig = $deviceConfig;
        $this->verificationQueue = $verificationQueue;
        $this->agentService = $agentService;
        $this->hardware = $hardware;
        $this->storageService = $storageService;
        $this->migrationService = $migrationService;
        $this->resourceMonitor = $resourceMonitor;
        $this->orphanDatasetService = $orphanDatasetService;
        $this->verificationService = $verificationService;
        $this->dateTimeService = $dateTimeService;
        $this->uptime = $uptime;
        $this->driveHealthService = $driveHealthService;
        $this->deviceSerial = $deviceSerial;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ADVANCED_STATUS_READ")
     *
     * @return Response
     */
    public function indexAction()
    {
        $uptime = $this->dateTimeService->getTime() - $this->uptime->getBootedAt();

        return $this->render(
            'Advanced/Status/index.html.twig',
            [
                "isScreenShotting" => $this->verificationService->hasInProgressVerification(),
                "agentsScreenShottingInfo" => $this->getScreenshottingAgentsInfo(),
                "queue" => $this->getScreenshotQueue(),
                "load" => $this->resourceMonitor->getCpuAvgLoad(),
                "displayModel" => $this->deviceConfig->getDisplayModel(),
                "serial" => $this->deviceSerial->get(),
                "buildDate" => $this->deviceConfig->getDeviceBornTime(),
                "totalMemoryMB" => $this->hardware->getPhysicalRamMiB(),
                "uptime" => $uptime,
                "osDriveStorage" => $this->storageService->getOsDriveInfo(),
                "storageController" => $this->hardware->getStorageController(),
                "drives" => $this->driveHealthService->getDriveHealth(),
                "missingDrives" => $this->driveHealthService->getMissing(),
                "pool" => $this->storageService->getPoolStatus(),
                "storageMigrations" => $this->getStorageMigrations(),
                "orphanedDatasets" => $this->getOrphans()
            ]
        );
    }

    /**
     * This method adds the hostname of the agent to the array returned by the verification queue.
     *
     * @return array
     */
    private function getScreenshotQueue()
    {
        $queue = $this->verificationQueue->getQueue();
        $output = array();

        foreach ($queue as $asset) {
            $agent = $this->agentService->get($asset->getAssetName());
            $output[] = array(
                "assetHostname" => $agent->getHostname(),
                "assetName" => $asset->getAssetName(),
                "snapshotTime" => $asset->getSnapshotTime(),
                "queuedTime" => $asset->getQueuedTime()
            );
        }

        return $output;
    }

    /**
     * This method is only view related. So it should stay here.
     * It just adds the hostname of the agent, to the information array of the
     * screenshotting process of each agent.
     * It also computes the time left.
     * @return array
     */
    private function getScreenshottingAgentsInfo()
    {
        $output = array();

        $inProgress = $this->verificationService->findInProgressVerification();
        if ($inProgress) {
            $agent = $this->agentService->get($inProgress->getAssetKey());

            $finish = $inProgress->getStartedAt()
                + $this->verificationService->getScreenshotTimeout()
                + $inProgress->getDelay();

            $info = [
                'agent' => $inProgress->getAssetKey(),
                'snap' => $inProgress->getSnapshot(),
                'hostname' => $agent->getHostname(),
                'timeLeft' => $finish - $this->dateTimeService->getTime()
            ];

            $output[] = $info;
        }

        return $output;
    }

    /**
     * Get list of non-dismissed storage migrations
     *
     * @return array Non-dismissed storage migrations
     */
    private function getStorageMigrations(): array
    {
        $allMigrations = $this->migrationService->getAllMigrations();
        $storageMigrations = [];
        foreach ($allMigrations as $migration) {
            if ($migration->getType() === ZpoolReplaceMigration::TYPE && !$migration->isDismissed()) {
                $storageMigrations[] = $migration;
            }
        }

        $storageMigrations = $this->chronologicallySortMigrations($storageMigrations);

        return $storageMigrations;
    }

    /**
     * Sort a list of migrations in chronological order based on scheduled time from newest to oldest
     *
     * @param array $migrations List of migrations to sort
     *
     * @return array Chronologically sorted list of migrations
     */
    private function chronologicallySortMigrations(array $migrations): array
    {
        usort($migrations, function (AbstractMigration $a, AbstractMigration $b) {
            return $a->getScheduleAt()->getTimestamp() < $b->getScheduleAt()->getTimestamp() ? 1 : -1;
        });

        return $migrations;
    }

    /**
     * Get a list of orphaned datasets
     *
     * @return array
     */
    private function getOrphans(): array
    {
        $orphans = $this->orphanDatasetService->findOrphanDatasets();

        array_walk(
            $orphans,
            function (&$orphan) {
                $orphan = [
                    "hostname" => $this->orphanDatasetService->getHostnameFromAgentInfo($orphan),
                    "name" => $orphan->getName(),
                    "size" => $orphan->getUsedSpace()
                ];
            }
        );

        return $orphans;
    }
}
