<?php

namespace Datto\Service\Offsite;

use Datto\Cloud\OffsiteSyncScheduleService;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\LocalConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\Networking\NetworkService;
use Datto\Resource\DateTimeService;
use Datto\Utility\Bandwidth\TrafficControl;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service that handles restricting bandwidth for any data leaving the device, based on a configured bandwidth schedule
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class BandwidthLimitService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var OffsiteSyncScheduleService */
    private $offsiteSyncScheduleService;

    /** @var LocalConfig */
    private $localConfig;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    /** @var SpeedSync */
    private $speedSync;

    /** @var NetworkService */
    private $networkService;

    /** @var TrafficControl */
    private $trafficControl;

    public function __construct(
        OffsiteSyncScheduleService $offsiteSyncScheduleService,
        LocalConfig $localConfig,
        DateTimeService $dateTimeService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        SpeedSync $speedSync,
        NetworkService $networkService,
        TrafficControl $trafficControl
    ) {
        $this->offsiteSyncScheduleService = $offsiteSyncScheduleService;
        $this->localConfig = $localConfig;
        $this->dateTimeService = $dateTimeService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->speedSync = $speedSync;
        $this->networkService = $networkService;
        $this->trafficControl = $trafficControl;
    }

    public function applyBandwidthRestrictions()
    {
        try {
            $currentUTC = $this->dateTimeService->getTime();
            // device-web considers Monday to be the start of the week, while the device thinks it should start on Sunday
            $startOfWeekSecond = $this->dateTimeService->getStartOfWeek($currentUTC) + DateTimeService::SECONDS_PER_DAY;
            // If the current time is before the start of the week as we know it (ex: It's Sunday), we are still in the previous week.
            $startOfWeekSecond = $startOfWeekSecond > $currentUTC ? $startOfWeekSecond - DateTimeService::SECONDS_PER_WEEK : $startOfWeekSecond;
            $currentWeekSecond = $currentUTC - $startOfWeekSecond;
            $scheduleApplied = false;
            foreach ($this->offsiteSyncScheduleService->getAll() as $scheduledRestriction) {
                // Figure out if the current time is within the range of an existing schedule, including wrap-around dates
                $scheduleEndsLaterInTheWeek = $scheduledRestriction['start'] < $scheduledRestriction['end']
                    && $scheduledRestriction['start'] <= $currentWeekSecond
                    && $scheduledRestriction['end'] > $currentWeekSecond;
                $scheduleEndWrapsAround = $scheduledRestriction['end'] < $scheduledRestriction['start']
                    && ($scheduledRestriction['end'] > $currentWeekSecond || $scheduledRestriction['start'] <= $currentWeekSecond);
                if ($scheduleEndsLaterInTheWeek || $scheduleEndWrapsAround) {
                    // We're within the timeframe of this restriction, so apply it
                    $bandwidthInKbps = $scheduledRestriction['speed'] * 8;
                    $this->applyScheduledBandwidthRestriction($bandwidthInKbps, $currentWeekSecond, $scheduledRestriction['end']);
                    $scheduleApplied = true;
                    break;
                }
            }
            if (!$scheduleApplied) {
                // Use the full pipe allowed by this device, for the rest of the week (until another schedule is applied)
                $deviceTxLimit = $this->localConfig->get('txSpeed') ?? 0;
                $bandwidthInKbps = $deviceTxLimit * 8;
                $endOfWeekSeconds = $this->dateTimeService->getStartOfWeek($currentWeekSecond) + DateTimeService::SECONDS_PER_WEEK;
                $this->applyScheduledBandwidthRestriction($bandwidthInKbps, $currentWeekSecond, $endOfWeekSeconds);
            }
        } catch (Throwable $t) {
            $this->logger->error('BWL0003 Unexpected error occurred while applying offsite bandwidth restrictions', ['error' => $t->getMessage()]);
            throw $t;
        }
    }

    private function applyScheduledBandwidthRestriction(int $bandwidthLimitInKbps, int $currentTime, int $scheduleEnd)
    {
        $networkInterfaceNames = $this->networkService->getPhysicalNetworkInterfaces();
        if ($bandwidthLimitInKbps > 0) {
            // Speedsync offsite target - get from /datto/config/local/serverAddress
            $serverIP = $this->localConfig->get('serverAddress');
            $remoteIPs = [];
            if ($serverIP === false) {
                $this->logger->warning('BWL0001 Unable to find server address in local config for offsite bandwidth limitation');
            } else {
                $remoteIPs[] = $serverIP;
            }
            // Limit bandwidth being used by any data that's leaving the box through the ethernet port!
            // Speedsync should know about all of the target addresses
            $remoteIPs = array_unique(array_merge($remoteIPs, $this->speedSync->getTargetAddresses()));

            // TODO: Handle Device Migration and RoundTrips to NAS devices over the network - could use RoundTrip.php->getTargets if you know which adapter the RTNAS is connected to
            foreach ($networkInterfaceNames as $interfaceName) {
                $adapterSpeed = $this->networkService->getInterfaceSpeed($interfaceName);
                $this->trafficControl->clearRules($interfaceName);
                $this->trafficControl->applyBandwidthLimit($interfaceName, $bandwidthLimitInKbps, $adapterSpeed, $remoteIPs);
            }
        } else {
            foreach ($networkInterfaceNames as $interfaceName) {
                $this->trafficControl->clearRules($interfaceName);
            }
            // Offsite bandwidth needs to be limited to 0, we will do that by pausing speedsync
            $devicePaused = $this->speedSyncMaintenanceService->isDevicePaused();
            $delayEndTime = $this->localConfig->get('delay', $scheduleEnd);
            if (($devicePaused && $delayEndTime < $scheduleEnd)
                || (!$devicePaused && $scheduleEnd > $currentTime)) {
                // We are already paused, but the current pause won't last long enough
                // OR we are not paused yet, and the scheduled end time is in the future
                $pauseTimeNeededSeconds = $scheduleEnd - $currentTime;
                $this->logger->info('BWL0002 Pausing speedsync to limit offsite bandwidth to 0', ['scheduleEnd' => $scheduleEnd]);

                $this->speedSyncMaintenanceService->pause($pauseTimeNeededSeconds);
            } else {
                // We already are configured correctly for the current time, no need to do anything else
                return;
            }
        }
    }
}
