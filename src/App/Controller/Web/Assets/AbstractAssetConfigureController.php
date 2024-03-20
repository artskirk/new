<?php

namespace Datto\App\Controller\Web\Assets;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Asset;
use Datto\Asset\AssetServiceInterface;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Billing\Service as BillingService;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Replication\ReplicationDevices;
use Datto\Samba\UserService;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Exception;

/**
 * Handles requests to the agent config page
 */
abstract class AbstractAssetConfigureController extends AbstractBaseController
{
    protected AssetServiceInterface $service;
    protected DeviceConfig $deviceConfig;
    protected UserService $userService;
    protected SpeedSyncMaintenanceService $speedSyncMaintenanceService;
    protected BillingService $billingService;

    private DeviceState $deviceState;

    public function __construct(
        NetworkService $networkService,
        AssetServiceInterface $service,
        DeviceState $deviceState,
        DeviceConfig $deviceConfig,
        UserService $userService,
        BillingService $billingService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->service = $service;
        $this->deviceState = $deviceState;
        $this->deviceConfig = $deviceConfig;
        $this->userService = $userService;
        $this->billingService = $billingService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    protected function getCommonParameters(Asset $asset)
    {
        return array_merge_recursive(
            $this->getNameAndTypeParameters($asset),
            $this->getLicenseParameters(),
            $this->getCommonLocalParameters($asset),
            $this->getCommonOffsiteParameters($asset),
            $this->getCommonReportingParameters($asset),
            $this->getCommonDeviceParameters(),
            $this->getCommonOriginDeviceParameters($asset)
        );
    }

    abstract protected function getNameAndTypeParameters(Asset $asset);

    abstract protected function getLicenseParameters();

    private function getCommonOriginDeviceParameters(Asset $asset): array
    {
        return array(
            'asset' => array(
                'originDevice' => array(
                    'isReplicated' => $asset->getOriginDevice()->isReplicated()
                )
            )
        );
    }

    private function getCommonDeviceParameters(): array
    {
        $domainError = false;
        $allUsers = array();
        try {
            $allUsers = $this->userService->getUsers();
        } catch (Exception $e) {
            $domainError = true;
        }
        return array(
            'device' => array(
                'users' => $allUsers,
                'domainError' => $domainError
            )
        );
    }

    private function getCommonLocalParameters(Asset $asset): array
    {
        $firstHour = 0;
        $lastHour = 0;
        $isWeekendSame = true;
        $sevenAmSet = false;
        $elevenAmSet = false;
        $threePmSet = false;
        $sevenPmSet = false;
        $elevenPmSet = false;

        $localSchedule = $asset->getLocal()->getSchedule();
        $isCustomSchedule = !$localSchedule->isStandardSchedule();
        if (!$isCustomSchedule) {
            $backupRange = $localSchedule->getBackupRange(WeeklySchedule::MONDAY);
            $firstHour = $backupRange['firstHour'];
            $lastHour = $backupRange['lastHour'];

            $isWeekendSame = $localSchedule->isWeekendSame(WeeklySchedule::MONDAY);
            if (!$isWeekendSame) {
                $saturdaySchedule = $localSchedule->getDay(WeeklySchedule::SATURDAY);
                $sevenAmSet = $saturdaySchedule[7];
                $elevenAmSet = $saturdaySchedule[11];
                $threePmSet = $saturdaySchedule[15];
                $sevenPmSet = $saturdaySchedule[19];
                $elevenPmSet = $saturdaySchedule[23];
            }
        }
        $defaultSchedule = new WeeklySchedule();

        return array(
            'asset' => array(
                'local' => array(
                    'paused' => $asset->getLocal()->isPaused(),
                    'interval' => array(
                        'minutes' => $asset->getLocal()->getInterval(),
                        'count' => $asset->getLocal()->getSchedule()
                            ->calculateBackupCount($asset->getLocal()->getInterval()),
                        'default' => LocalSettings::DEFAULT_INTERVAL
                    ),
                    'schedule' => $localSchedule->getSchedule(),
                    'defaultSchedule' => $defaultSchedule->getSchedule(),
                    'scheduleParameters' => array(
                        'isCustomSchedule' => $isCustomSchedule,
                        'firstScheduleHour' => $firstHour,
                        'defaultFirstScheduleHour' => WeeklySchedule::FIRST_WEEKDAY_HOUR_DEFAULT,
                        'defaultLastScheduleHour' => WeeklySchedule::LAST_WEEKDAY_HOUR_DEFAULT,
                        'lastScheduleHour' => $lastHour,
                        'isWeekendScheduleSame' => !$isCustomSchedule && $isWeekendSame,
                        'sevenAmWeekendBackup' => $sevenAmSet,
                        'elevenAmWeekendBackup' => $elevenAmSet,
                        'threePmWeekendBackup' => $threePmSet,
                        'sevenPmWeekendBackup' => $sevenPmSet,
                        'elevenPmWeekendBackup' => $elevenPmSet,
                        'defaultBackupDayRange' => 'weekdays'
                    ),
                    'retention' => array(
                        'daily' => $asset->getLocal()->getRetention()->getDaily(),
                        'weekly' => $asset->getLocal()->getRetention()->getWeekly(),
                        'monthly' => $asset->getLocal()->getRetention()->getMonthly(),
                        'keep' => $asset->getLocal()->getRetention()->getMaximum()
                    )
                )
            )
        );
    }

    private function getCommonOffsiteParameters(Asset $asset): array
    {
        $isInfiniteRetention = $this->billingService->isInfiniteRetention();
        $isTimeBasedRetention = $this->billingService->isTimeBasedRetention();
        $isLocalOnly = $this->billingService->isLocalOnly();

        $weeklyOffsiteBackupCount = $asset->getOffsite()->calculateWeeklyOffsiteCount(
            $asset->getLocal()->getInterval(),
            $asset->getLocal()->getSchedule()
        );

        $offsiteTarget = $asset->getOffsiteTarget();
        $targetDevice = null;

        if (SpeedSync::isPeerReplicationTarget($offsiteTarget)) {
            $replicationDevices = ReplicationDevices::createOutboundReplicationDevices();
            $this->deviceState->loadRecord($replicationDevices);
            $targetDevice = $replicationDevices->getDevice($offsiteTarget);
            if ($targetDevice) {
                $targetDevice = $targetDevice->toArray();
            }
        }

        $offsiteRetention = $asset->getOffsite()->getRetention();
        $offsiteRetentionDefaults = Retention::createApplicableDefault($this->billingService);

        return array(
            'asset' => array(
                'offsite' => array(
                    'paused' => $this->speedSyncMaintenanceService->isAssetPaused($asset->getKeyName()),
                    'interval' => $asset->getOffsite()->getReplication(),
                    'priority' => $asset->getOffsite()->getPriority(),
                    'schedule' => $asset->getOffsite()->getSchedule()->getSchedule(),
                    'backupCount' => $weeklyOffsiteBackupCount,
                    'defaultReplication' => OffsiteSettings::DEFAULT_REPLICATION,
                    'offsiteTarget' => $offsiteTarget,
                    'targetDevice' => $targetDevice,
                    'retention' => array(
                        'isInfiniteRetention' => $isInfiniteRetention,
                        'isTimeBasedRetention' => $isTimeBasedRetention,
                        'daily' => $offsiteRetention->getDaily(),
                        'weekly' => $offsiteRetention->getWeekly(),
                        'monthly' => $offsiteRetention->getMonthly(),
                        'keep' => $offsiteRetention->getMaximum(),
                        'defaults' => [
                            'daily' => $offsiteRetentionDefaults->getDaily(),
                            'weekly' => $offsiteRetentionDefaults->getWeekly(),
                            'monthly' => $offsiteRetentionDefaults->getMonthly(),
                            'keep' => $offsiteRetentionDefaults->getMaximum()
                        ]
                    )
                ),
                'isLocalOnly' => $isLocalOnly
            )
        );
    }

    private function getCommonReportingParameters(Asset $asset): array
    {
        return array(
            'asset' => array(
                'reporting' => array(
                    'critical' => array(
                        'emails' => $asset->getEmailAddresses()->getCritical(),
                    ),
                    'warning' => array(
                        'emails' => $asset->getEmailAddresses()->getWarning(),
                    ),
                    'log' => array(
                        'emails' => $asset->getEmailAddresses()->getLog(),
                    )
                )
            )
        );
    }
}
