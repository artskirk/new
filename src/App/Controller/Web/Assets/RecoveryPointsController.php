<?php

namespace Datto\App\Controller\Web\Assets;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetSummaryService;
use Datto\Asset\AssetType;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\Filesystem;
use Datto\Feature\FeatureService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recovery Points Controller.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class RecoveryPointsController extends AbstractBaseController
{
    private AssetService $assetService;
    private RecoveryPointInfoService $recoveryPointInfoService;
    private FeatureService $featureService;
    private AssetSummaryService $assetSummaryService;
    private EncryptionService $encryptionService;

    public function __construct(
        NetworkService $networkService,
        AssetSummaryService $assetSummaryService,
        AssetService $assetService,
        RecoveryPointInfoService $recoveryPointsInfoService,
        FeatureService $featureService,
        EncryptionService $encryptionService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetSummaryService = $assetSummaryService;
        $this->assetService = $assetService;
        $this->recoveryPointInfoService = $recoveryPointsInfoService;
        $this->featureService = $featureService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Renders the index of the Agent's log page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * Permissions are dynamically checked in the method but we are still required to have a permission annotation
     * @Datto\App\Security\RequiresPermission("PERMISSION_NONE")
     *
     * @param string $assetKey
     * @return Response
     */
    public function indexAction(string $assetKey): Response
    {
        $asset = $this->assetService->get($assetKey);

        if ($this->encryptionService->isAgentSealed($assetKey)) {
            return $this->redirect($this->generateUrl('access_denied_encryption'));
        }

        $hasLocalRestores = false;
        $hasOffsiteRestores = false;
        $points = $this->recoveryPointInfoService->getRecoveryPointsInfoAsArray($asset);
        foreach ($points as $point) {
            if (!empty($point['localRestores'])) {
                $hasLocalRestores = true;
            } elseif (!empty($point['offsiteRestores'])) {
                $hasOffsiteRestores = true;
            }
        }

        $vssSupported = $asset->isType(AssetType::WINDOWS_AGENT);

        $referer = null;
        if ($asset->isType(AssetType::AGENT)) {
            $this->denyAccessUnlessGranted('PERMISSION_AGENT_RECOVERY_POINTS_READ');
            $referer = $this->generateUrl('agents_index');
        } elseif ($asset->isType(AssetType::SHARE)) {
            $this->denyAccessUnlessGranted('PERMISSION_SHARE_RECOVERY_POINTS_READ');
            $referer = $this->generateUrl('shares_index');
        }

        $params = [
            'assetKey' => $assetKey,
            'assetDisplayName' => $asset->getDisplayName(),
            'assetClass' => get_class($asset),
            'isArchived' => $asset->getLocal()->isArchived(),
            'isReplicated' => $asset->getOriginDevice()->isReplicated(),
            'isZfsShare' => $asset->isType(AssetType::ZFS_SHARE),
            'summary' => $this->getSummaryAsArray($asset),
            'recoveryPoints' => $points,
            'integrityCheckEnabled' => $asset->getLocal()->isIntegrityCheckEnabled(),
            'ransomwareCheckEnabled' => $asset->getLocal()->isRansomwareCheckEnabled(),
            'referer' => $referer,
            'screenshotStatusCodes' => [
                'none' => RecoveryPoint::NO_SCREENSHOT,
                'successful' => RecoveryPoint::SUCCESSFUL_SCREENSHOT,
                'unsuccessful' => RecoveryPoint::UNSUCCESSFUL_SCREENSHOT,
                'queued' => RecoveryPoint::SCREENSHOT_QUEUED,
                'inProgress' => RecoveryPoint::SCREENSHOT_INPROGRESS
            ],
            'offsiteStatusCodes' => [
                'none' => SpeedSync::OFFSITE_NONE,
                'queued' => SpeedSync::OFFSITE_QUEUED,
                'synced' => SpeedSync::OFFSITE_SYNCED,
                'processing' => SpeedSync::OFFSITE_PROCESSING,
                'syncing' => SpeedSync::OFFSITE_SYNCING
            ],
            'displayBackupType' => $this->shouldDisplayBackupType($asset),
            'vssSupported' => $vssSupported,
            'verificationSupported' =>
                $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS, null, $asset),
            'fileIntegrityCheckSupported' =>
                $this->featureService->isSupported(FeatureService::FEATURE_FILESYSTEM_INTEGRITY_CHECK, null, $asset),
            'ransomwareDetectionSupported' =>
                $this->featureService->isSupported(FeatureService::FEATURE_RANSOMWARE_DETECTION, null, $asset),
            'offsitingSupported' =>
                $this->featureService->isSupported(FeatureService::FEATURE_OFFSITE, null, $asset),
            'hasLocalRestores' => $hasLocalRestores,
            'hasOffsiteRestores' => $hasOffsiteRestores
        ];

        return $this->render(
            'Assets/RecoveryPoints/index.html.twig',
            $params
        );
    }

    /**
     * Only standard agents get their backup types displayed.
     *
     * @param Asset $asset
     * @return bool
     */
    private function shouldDisplayBackupType(Asset $asset): bool
    {
        if ($asset->isType(AssetType::AGENT)) {
            /** @var Agent $asset */
            return !$asset->isRescueAgent() && !$asset->getOriginDevice()->isReplicated();
        }

        return false;
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getSummaryAsArray(Asset $asset): array
    {
        // Until replicated assets receive their first offsite point, speedsync doesn't
        // create the zfs dataset which makes getting the summary impossible
        if ($asset->getOriginDevice()->isReplicated() && !$asset->hasDatasetAndPoints()) {
            return [];
        }
        return $this->assetSummaryService->getSummary($asset)->toArray();
    }
}
