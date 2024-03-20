<?php
namespace Datto\App\Controller\Web\Report;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\Retention;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for the Continuity Audit report page
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class ContinuityController extends AbstractBaseController
{
    const DAY_IN_HOURS = 24;
    const WEEK_IN_HOURS = 24 * 7;
    const RETENTION_MONTH_IN_HOURS = 24 * 31;
    const YEAR_IN_HOURS = 24 * 356;

    private AssetService $assetService;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private Filesystem $filesystem;
    private RecoveryPointInfoService $recoveryPointInfoService;
    private TranslatorInterface $translator;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        DateTimeService $dateTimeService,
        DateTimeZoneService $dateTimeZoneService,
        Filesystem $filesystem,
        RecoveryPointInfoService $recoveryPointInfoService,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
        $this->dateTimeService = $dateTimeService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->filesystem = $filesystem;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->translator = $translator;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_CONTINUITY_AUDIT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CONTINUITY_AUDIT_VIEW")
     *
     * @param int|null $startTime
     * @param int|null $endTime
     * @return Response
     */
    public function indexAction(int $startTime = null, int $endTime = null): Response
    {
        if (!isset($startTime)) {
            $startTimeObject = $this->dateTimeService->getRelative(DateTimeService::PAST_MONTH);
            $startTime = $startTimeObject->getTimestamp();
        }
        if (!isset($endTime)) {
            $endTimeObject = $this->dateTimeService->now();
            $endTime = $endTimeObject->getTimestamp();
        }

        $assets = $this->assetService->getAll();
        $assetsData = [];
        foreach ($assets as $asset) {
            if ($asset->getOriginDevice()->isReplicated()) {
                continue; // replicated assets are not included in the continuity audit
            }

            $isShare = $asset->isType(AssetType::SHARE);
            if ($isShare) {
                $os = null;
            } else {
                /** @var Agent $asset */
                $os = $asset->getOperatingSystem()->getName();
            }

            $assetsData[] = [
                'displayName' => $asset->getDisplayName(),
                'pairName' => $asset->getPairName(),
                'keyName' => $asset->getKeyName(),
                'type' => $asset->getType(),
                'os' => $os,
                'retention' => [
                    'local' => $this->generateRetentionArray($asset->getLocal()->getRetention()),
                    'offsite' => $this->generateRetentionArray($asset->getOffsite()->getRetention()),
                ]
            ];
        }

        $params = [
            'assets' => $assetsData,
            'startTime' => $startTime,
            'endTime' => $endTime
        ];

        return $this->render('Report/Continuity/index.html.twig', $params);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_CONTINUITY_AUDIT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CONTINUITY_AUDIT_VIEW")
     *
     * @param int|null $startTime
     * @param int|null $endTime
     * @return Response
     */
    public function csvAction(int $startTime = null, int $endTime = null): Response
    {
        $assets = $this->assetService->getAll();
        $table = [
            [
                $this->translator->trans('common.agent'),
                $this->translator->trans('report.continuity.csv.date'),
                $this->translator->trans('report.continuity.csv.status.local'),
                $this->translator->trans('report.continuity.csv.status.screenshot'),
                $this->translator->trans('report.continuity.csv.status.offsite'),
                $this->translator->trans('report.continuity.csv.local.intradaily'),
                $this->translator->trans('report.continuity.csv.local.daily'),
                $this->translator->trans('report.continuity.csv.local.weekly'),
                $this->translator->trans('report.continuity.csv.local.delete'),
                $this->translator->trans('report.continuity.csv.offsite.intradaily'),
                $this->translator->trans('report.continuity.csv.offsite.daily'),
                $this->translator->trans('report.continuity.csv.offsite.weekly'),
                $this->translator->trans('report.continuity.csv.offsite.delete'),
            ]
        ];
        foreach ($assets as $asset) {
            if ($asset->getOriginDevice()->isReplicated()) {
                continue; // replicated assets are not included in the continuity audit
            }
            $assetPointsData = $this->getAssetPointsStatuses($asset, $startTime, $endTime);
            $localRetention = $this->getHumanReadableRetention($asset->getLocal()->getRetention());
            $offsiteRetention = $this->getHumanReadableRetention($asset->getOffsite()->getRetention());
            foreach ($assetPointsData as $point) {
                $table[] = array_merge(
                    array_values($point),
                    array_values($localRetention),
                    array_values($offsiteRetention)
                );
            }
        }

        $dateFormat = str_replace('/', '-', $this->dateTimeZoneService->universalDateFormat('date'));
        $filename = $this->translator->trans('report.continuity.export.filename', [
            '%start%' => $this->dateTimeService->format($dateFormat, $startTime),
            '%end%' => $this->dateTimeService->format($dateFormat, $endTime),
            '%extension%' => 'csv'
        ]);

        $response = new StreamedResponse();
        $response->setCallback(function () use ($table) {
            $handle = $this->filesystem->open('php://output', 'w+');
            foreach ($table as $row) {
                $this->filesystem->putCsv($handle, $row);
            }
            $this->filesystem->close($handle);
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_CONTINUITY_AUDIT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CONTINUITY_AUDIT_VIEW")
     *
     * @param int|null $startTime
     * @param int|null $endTime
     * @return Response
     */
    public function printAction(int $startTime = null, int $endTime = null): Response
    {

        $assets = $this->assetService->getAll();
        $assetData = [];
        foreach ($assets as $asset) {
            if ($asset->getOriginDevice()->isReplicated()) {
                continue; // replicated assets are not displayed for continuity audit
            }

            if ($asset instanceof Agent) {
                $os = $asset->getOperatingSystem()->getName();
            } else {
                $os = null;
            }
            $assetData[] = [
                'displayName' => $asset->getDisplayName(),
                'pairName' => $asset->getPairName(),
                'type' => $asset->getType(),
                'os' => $os,
                'retention' => [
                    'local' => $this->generateRetentionArray($asset->getLocal()->getRetention()),
                    'offsite' => $this->generateRetentionArray($asset->getOffsite()->getRetention())
                ],
                'points' => $this->getAssetPoints($asset, $startTime, $endTime),
            ];
        }

        return $this->render('Report/Continuity/print.html.twig', [
            'startTime' => $startTime,
            'endTime' => $endTime,
            'assets' => $assetData,
        ]);
    }

    private function getAssetPointsStatuses(Asset $asset, int $startTime, int $endTime): array
    {
        $assetName = $asset->getPairName();
        $dateTimeFormat = $this->dateTimeZoneService->universalDateFormat('date-time-short');

        $rawPoints = $this->getAssetPoints($asset, $startTime, $endTime);

        $points = [];
        foreach ($rawPoints as $epoch => $point) {
            $points[] = [
                'asset' => $assetName,
                'timestamp' => $this->dateTimeService->format($dateTimeFormat, $point['snapshotEpoch']),
                'localStatus' => $this->getLocalStatus($point),
                'screenshotStatus' => $this->getScreenshotStatus($point),
                'offsiteStatus' => $this->getOffsiteStatus($point),
            ];
        }
        return $points;
    }

    private function getAssetPoints(Asset $asset, int $startTime, int $endTime): array
    {
        $this->recoveryPointInfoService->refreshCaches($asset);
        $allPoints = $this->recoveryPointInfoService->getRecoveryPointsInfoAsArray($asset);
        $points = [];
        foreach ($allPoints as $epoch => $point) {
            if ($epoch >= $startTime && $epoch <= $endTime) {
                $points[] = $point;
            }
        }
        return $points;
    }

    private function getLocalStatus(array $point): string
    {
        if ($point['existsLocally']) {
            return $this->translator->trans('report.continuity.status.exists.local');
        }
        return '';
    }

    private function getOffsiteStatus(array $point): string
    {
        if ($point['existsOffsite']) {
            return $this->translator->trans('report.continuity.status.exists.cloud');
        }
        if ($point['offsiteStatus'] === RecoveryPointInfoService::OFFSITE_STATUS_QUEUED) {
            return $this->translator->trans('report.continuity.status.offsite.queued');
        }
        if ($point['offsiteStatus'] === RecoveryPointInfoService::OFFSITE_STATUS_PROGRESS) {
            return $this->translator->trans('report.continuity.status.offsite.progress');
        }
        return '';
    }

    private function getScreenshotStatus(array $point): string
    {
        if ($point['screenshotStatus'] === RecoveryPoint::SUCCESSFUL_SCREENSHOT) {
            return $this->translator->trans('report.continuity.status.screenshot.success');
        }
        if ($point['screenshotStatus'] === RecoveryPoint::UNSUCCESSFUL_SCREENSHOT) {
            return $this->translator->trans('report.continuity.status.screenshot.failed');
        }
        if ($point['screenshotStatus'] === RecoveryPoint::SCREENSHOT_QUEUED) {
            return $this->translator->trans('report.continuity.status.screenshot.queued');
        }
        if ($point['screenshotStatus'] === RecoveryPoint::SCREENSHOT_INPROGRESS) {
            return $this->translator->trans('report.continuity.status.screenshot.progress');
        }
        return '';
    }

    /**
     * @param Retention $retention retention object
     * @return array UI-displayable retention settings as an array
     */
    private function generateRetentionArray(Retention $retention): array
    {
        $intraDaily = $retention->getDaily();
        $daily = $retention->getWeekly() - $intraDaily;
        $weekly = $retention->getMonthly() - $daily - $intraDaily;
        $maximum = $retention->getMaximum();

        return [
            'intradaily' => $intraDaily < $maximum ? $intraDaily : 0,
            'daily' => $daily < $maximum ? $daily : 0,
            'weekly' => $weekly < $maximum ? $weekly : 0,
            'delete' => $maximum,
        ];
    }

    private function getHumanReadableRetention(Retention $retention): array
    {
        $retentionData = $this->generateRetentionArray($retention);
        foreach ($retentionData as $index => $duration) {
            $retentionData[$index] = $this->getHumanRedableRetentionDuration($duration);
        }
        return $retentionData;
    }

    private function getHumanRedableRetentionDuration(int $duration): string
    {
        if ($duration === 0) {
            return '';
        }
        if ($duration === Retention::NEVER_DELETE) {
            return $this->translator->trans('common.never');
        }
        if ($duration >= self::DAY_IN_HOURS * 364) {
            $count = $duration / self::YEAR_IN_HOURS;
            return $this->translator->trans('common.years.count', ['%count%' => $count]);
        }
        if ($duration >= self::RETENTION_MONTH_IN_HOURS && $duration % self::RETENTION_MONTH_IN_HOURS === 0) {
            $count = $duration / self::RETENTION_MONTH_IN_HOURS;
            return $this->translator->trans('common.months.count', ['%count%' => $count]);
        }
        if ($duration >= self::WEEK_IN_HOURS && $duration % self::WEEK_IN_HOURS === 0) {
            $count = $duration / self::WEEK_IN_HOURS;
            return $this->translator->trans('common.weeks.count', ['%count%' => $count]);
        }
        $count = $duration / self::DAY_IN_HOURS;
        return $this->translator->trans('common.days.count', ['%count%' => $count]);
    }
}
