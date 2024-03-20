<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Common\Resource\Filesystem;
use Datto\Connection\Service\ConnectionService;
use Datto\Device\Serial;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the Restore index page
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class RestoreController extends AbstractBaseController
{
    const RESTORE_NAMES_TO_FEATURES = [
        'fileRestore' => FeatureService::FEATURE_RESTORE_FILE,
        'volumeRestore' => FeatureService::FEATURE_RESTORE_VOLUME,
        'fileRestoreWithAcls' => FeatureService::FEATURE_RESTORE_FILE_ACLS,
        'localVirt' => FeatureService::FEATURE_RESTORE_VIRTUALIZATION_LOCAL,
        'hybridVirt' => FeatureService::FEATURE_RESTORE_VIRTUALIZATION_HYBRID,
        'esxVirt' => FeatureService::FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR,
        'esxUpload' => FeatureService::FEATURE_RESTORE_HYPERVISOR_UPLOAD,
        'bareMetalRestore' => FeatureService::FEATURE_RESTORE_BMR,
        'imageExport' => FeatureService::FEATURE_RESTORE_IMAGE_EXPORT,
        'iscsiRestore' => FeatureService::FEATURE_RESTORE_ISCSI,
        'iscsiRollback' => FeatureService::FEATURE_RESTORE_ISCSI_ROLLBACK
    ];

    private RestoreService $restoreService;
    private AssetService $assetService;
    private ConnectionService $connectionService;
    private FeatureService $featureService;
    private DateTimeService $dateTimeService;
    private InsightsService $insightsService;
    private Serial $deviceSerial;

    public function __construct(
        NetworkService $networkService,
        RestoreService $restoreService,
        AssetService $assetService,
        ConnectionService $connectionService,
        FeatureService $featureService,
        DateTimeService $dateTimeService,
        InsightsService $insightsService,
        Serial $deviceSerial,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->restoreService = $restoreService;
        $this->assetService = $assetService;
        $this->connectionService = $connectionService;
        $this->featureService = $featureService;
        $this->dateTimeService = $dateTimeService;
        $this->insightsService = $insightsService;
        $this->deviceSerial = $deviceSerial;
    }

    /**
     * Returns the restore page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_READ")
     *
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request): Response
    {
        $activeRestores = $this->sortRestores($this->restoreService->getAll());
        $activeRestoresData = [];
        foreach ($activeRestores as $restore) {
            $activeRestoresData[] = $this->generateRestoreData($restore, false);
        }

        $orphanRestores = $this->sortRestores($this->restoreService->getOrphans());
        $orphanRestoresData = [];
        foreach ($orphanRestores as $restore) {
            $orphanRestoresData[] = $this->generateRestoreData($restore, true);
        }

        $assets = $this->assetService->getAll();

        $localAssetsData = [];
        $replicatedAssetsData = [];
        $rescueAgentsData = [];
        $archivedAssetsData = [];
        foreach ($assets as $asset) {
            // No restore options for ZFS Shares
            if ($asset->isType(AssetType::ZFS_SHARE)) {
                continue;
            }

            $isReplicated = $asset->getOriginDevice()->isReplicated();
            $isArchived = $asset->getLocal()->isArchived();
            $restoreTypes = $this->getAllowedRestoreTypesForAsset($asset);
            $isRescueAgent = $asset instanceof Agent && $asset->isRescueAgent();
            $isGeneric = $asset instanceof Agent && !$asset->isSupportedOperatingSystem();

            $assetData = [
                'pairName' => $asset->getPairName() == $asset->getUuid() ? '' : $asset->getPairName(),
                'keyName' => $asset->getKeyName(),
                'hostname' => $asset->getDisplayName(),
                'recoveryPoints' => $this->getRecoveryPoints($asset),
                'showRansomware' => $asset->getLocal()->getRansomwareSuspensionEndTime() < $this->dateTimeService->getTime(),
                'blockingRestores' => $this->getBlockingRestores($asset->getKeyName(), $activeRestoresData),
                'restoreTypes' => $restoreTypes,
                'isGeneric' => $isGeneric
            ];

            if ($isReplicated && !$isRescueAgent) {
                $replicatedAssetsData[] = $assetData;
            } elseif ($isArchived) {
                $archivedAssetsData[] = $assetData;
            } elseif ($isRescueAgent) {
                $rescueAgentsData[] = $assetData;
            } else {
                $localAssetsData[] = $assetData;
            }
        }

        $allowedTypes = $this->getAllowedRestoreTypes();
        $deviceSerial = $this->deviceSerial->get();

        return $this->render(
            'Restore/index.html.twig',
            array(
                'activeRestores' => $activeRestoresData,
                'orphanRestores' => $orphanRestoresData,
                'comparisons' => $this->getComparisons(),
                'allowedTypes' => $allowedTypes,
                'assetGroups' => [
                    'localAssets' => $localAssetsData,
                    'replicatedAssets' => $replicatedAssetsData,
                    'rescueAgents' => $rescueAgentsData,
                    'archivedAssets' => $archivedAssetsData
                ],
                'connections' => $this->getConnections(),
                'preselectAsset' => $request->query->get('assetName'),
                'preselectRestore' => $request->query->get('restoreType'),
                'validRestoreParameters' => $this->validateRequestParameters($request),
                'deviceSerial' => $deviceSerial
            )
        );
    }

    /**
     * Validates request parameters, sets banner display boolean if they are not.
     * Returning true means the checked parameters are valid
     *
     * @return bool
     */
    private function validateRequestParameters(Request $request) : bool
    {
        $allowedTypes = $this->getAllowedRestoreTypes();
        $assetKeys = $this->assetService->getAllKeyNames();

        $assetKey = $request->query->get('assetName');
        $restoreType = $request->query->get('restoreType');

        if ((is_null($restoreType) || in_array($restoreType, $allowedTypes)) &&
            (is_null($assetKey) || in_array($assetKey, $assetKeys))) {
            return true;
        }

        return false;
    }

    /**
     * Forwards an external restore request. Only forwards assetName and restoreType params
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_READ")
     *
     * @param Request $request
     * @return Response
     */
    public function externalRequestAction(Request $request)
    {
        return $this->redirectToRoute(
            'restore',
            [
                'assetName' => $request->query->get('name'),
                'restoreType' => $request->query->get('restoreType')
            ]
        );
    }

    /**
     * @param string $assetKey
     * @return bool
     */
    private function isExternalNas(string $assetKey): bool
    {
        try {
            $isExternalNas = $this->assetService->exists($assetKey)
                && $this->assetService->get($assetKey)->isType(AssetType::EXTERNAL_NAS_SHARE);
        } catch (\Exception $e) {
            $isExternalNas = false; // Unable to load the asset
        }

        return $isExternalNas;
    }

    /**
     * @param Restore $restore
     * @param bool $isOrphan
     * @return array
     */
    private function generateRestoreData(Restore $restore, bool $isOrphan = false): array
    {
        $options = $restore->getOptions();
        $complete = $options['complete'] ?? true;
        $failed = $options['failed'] ?? false;
        $hypervisor = $options['connectionName'] ?? false;
        if ($restore->getSuffix() === RestoreType::ACTIVE_VIRT) {
            $restoreType = $hypervisor === 'Local KVM' ? 'localVirt' : 'esxVirt';
        } elseif ($restore->getSuffix() === 'iscsi' && $this->isExternalNas($restore->getAssetKey())) {
            $restoreType = 'fileRestoreWithAcls';
        } else {
            $restoreTypes = [
                RestoreType::FILE => 'fileRestore',
                RestoreType::EXPORT => 'imageExport',
                RestoreType::ESX_UPLOAD => 'esxUpload',
                'iscsi' => 'iscsiRestore',
                RestoreType::ISCSI_RESTORE => 'volumeRestore',
                RestoreType::RESCUE => 'rescue',
                RestoreType::HYBRID_VIRT => 'hybridVirt',
                RestoreType::BMR => 'bareMetalRestore',
                RestoreType::DIFFERENTIAL_ROLLBACK => 'differentialRollback'
            ];
            $restoreType = $restoreTypes[$restore->getSuffix()];
        }

        $hypervisorType = '';
        if ($hypervisor) {
            $connection = $this->connectionService->get($hypervisor);
            if ($connection) {
                $hypervisorType = $connection->getType()->value();
            }
        }

        $isVirtualization = in_array($restore->getSuffix(), RestoreType::VIRTUALIZATIONS, true);

        $isRestoreRemovable = !in_array($restore->getSuffix(), RestoreType::NON_USER_REMOVABLE_RESTORE_TYPES, true) &&
            ($this->isGranted('PERMISSION_RESTORE_VIRTUALIZATION_DELETE') || !$isVirtualization);

        return [
            'asset' => $restore->getAssetKey(),
            'hostname' => $restore->getHostname() ?: $restore->getAssetKey(),
            'point' => $restore->getPoint(),
            'inProgress' => !$complete,
            'failed' => $failed,
            'type' => $restoreType,
            'activationTime' => $restore->getActivationTime(),
            'hypervisor' => $hypervisor,
            'hypervisorType' => $hypervisorType,
            'isOrphan' => $isOrphan,
            'isUserManageable' => !in_array($restore->getSuffix(), RestoreType::NON_USER_MANAGEABLE_RESTORE_TYPES, true),
            'isUserRemovable' => $isRestoreRemovable
        ];
    }

    /**
     * @param Restore[] $restoreList
     * @return Restore[]
     */
    private function sortRestores(array $restoreList): array
    {
        usort($restoreList, function (Restore $restore1, Restore $restore2) {
            $sortKey1 = $restore1->getAssetKey() . $restore1->getPoint();
            $sortKey2 = $restore2->getAssetKey() . $restore2->getPoint();
            return $sortKey1 <=> $sortKey2;
        });

        return $restoreList;
    }

    /**
     * @return array $comparisons
     */
    private function getComparisons(): array
    {
        $comparisons = $this->insightsService->getCurrent();
        $comparisonData = [];
        foreach ($comparisons as $comparison) {
            $comparisonData[] = [
                'keyname' => $comparison->getAgent()->getKeyName(),
                'hostname' => $comparison->getAgent()->getDisplayName(),
                'point1' => $comparison->getFirstPoint(),
                'point2' => $comparison->getSecondPoint(),
            ];
        }

        return $comparisonData;
    }

    /**
     * @return array
     */
    private function getAllowedRestoreTypes(): array
    {
        $supportedRestores = array_filter(
            self::RESTORE_NAMES_TO_FEATURES,
            function ($item) {
                if ($item === FeatureService::FEATURE_RESTORE_ISCSI_ROLLBACK && !$this->isGranted('PERMISSION_RESTORE_ISCSI_ROLLBACK_WRITE')) {
                    return false;
                }

                return $this->featureService->isSupported($item);
            }
        );
        $supportedRestoreNames = array_keys($supportedRestores);

        return $supportedRestoreNames;
    }

    private function getAllowedRestoreTypesForAsset(Asset $asset): array
    {
        $supportedRestores = array_filter(
            self::RESTORE_NAMES_TO_FEATURES,
            function ($item) use ($asset) {
                return $this->featureService->isSupported($item, null, $asset);
            }
        );
        $supportedRestoreNames = array_keys($supportedRestores);

        return $supportedRestoreNames;
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getRecoveryPoints(Asset $asset): array
    {
        $recoveryPoints = [];

        /** @var RecoveryPoint $recoveryPoint */
        foreach ($asset->getLocal()->getRecoveryPoints()->getAll() as $recoveryPoint) {
            $ransomwareResults = $recoveryPoint->getRansomwareResults();
            $recoveryPoints[$recoveryPoint->getEpoch()] = [
                'ransomware' => $ransomwareResults && $ransomwareResults->hasRansomware(),
                'isLocal' => true,
                'isOffsite' => false,
            ];
        }
        foreach ($asset->getOffsite()->getRecoveryPoints()->getAll() as $recoveryPoint) {
            $epoch = $recoveryPoint->getEpoch();
            if (array_key_exists($epoch, $recoveryPoints)) {
                $recoveryPoints[$epoch]['isOffsite'] = true;
            } else {
                /** @psalm-suppress PossiblyNullReference */
                $recoveryPoints[$epoch] = [
                    'ransomware' => $recoveryPoint->getRansomwareResults() && $recoveryPoint->getRansomwareResults()->hasRansomware(),
                    'isLocal' => false,
                    'isOffsite' => true,
                ];
            }
        }
        ksort($recoveryPoints);

        return $recoveryPoints;
    }

    /**
     * @return string[]
     */
    private function getConnections(): array
    {
        $connections = $this->connectionService->getAll();
        $connectionList = [];
        foreach ($connections as $connection) {
            $connectionList[] = $connection->getName();
        }
        return $connectionList;
    }

    /**
     * @param string $assetKey
     * @param array $activeRestores
     * @return array
     */
    private function getBlockingRestores(string $assetKey, array $activeRestores): array
    {
        $blockingRestores = [];
        foreach ($activeRestores as $restore) {
            $isThisAsset = ($restore['asset'] === $assetKey);
            $isRelevantType = in_array($restore['type'], ['localVirt', 'hybridVirt', 'esxVirt']);

            if ($isThisAsset && $isRelevantType) {
                $blockingRestores[] = $restore;
            }
        }
        return $blockingRestores;
    }
}
