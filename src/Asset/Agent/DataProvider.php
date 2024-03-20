<?php

declare(strict_types=1);

namespace Datto\Asset\Agent;

use Datto\Connection\Libvirt\KvmConnection;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class DataProvider
{
    private RestoreService $restoreService;
    private Router $router;
    private InsightsService $insightsService;

    public function __construct(RestoreService $restoreService, Router $router, InsightsService $insightsService)
    {
        $this->restoreService = $restoreService;
        $this->router = $router;
        $this->insightsService = $insightsService;
    }

    /**
     * @param string $agentName Agent name
     * @return array
     */
    public function getRestores(string $agentName): array
    {
        $restores = $this->restoreService->getForAsset($agentName);
        $data = [];

        foreach ($restores as $restore) {
            // Agent is either a rescue agent and will always have this restore, or a non-rescue agent and will
            // never have it. If the former, then we want to ignore it so that the agent is actually removable.
            if ($restore->getSuffix() === RestoreType::RESCUE) {
                continue;
            }

            $options = $restore->getOptions();

            $type = $restore->getSuffix();
            if ($type === RestoreType::ACTIVE_VIRT) {
                $hypervisor = $options['connectionName'] ?? false;
                $type = $hypervisor === KvmConnection::CONNECTION_NAME ?
                    RestoreType::ACTIVE_VIRT : RestoreType::ESX_VIRT;
            }

            $data[] = [
                'assetKey' => $restore->getAssetKey(),
                'snapshotEpoch' => $restore->getPoint(),
                'type' => $type,
                'activationTime' => $restore->getActivationTime(),
                'connectionName' => $options['connectionName'] ?? null,
                'manageUrl' =>  $this->getManageUrl($restore),
                'isRemovable' => $this->restoreService->isRemovableByUser($restore),
                'imageType' => $options['image-type'] ?? null,
                'networkExport' => $options['network-export'] ?? null
            ];
        }

        return $data;
    }

    /**
     * @param Restore $restore
     * @return string
     */
    private function getManageUrl(Restore $restore): string
    {
        switch ($restore->getSuffix()) {
            case RestoreType::ESX_UPLOAD:
                $options = $restore->getOptions();
                return $this->router->generate('esx_upload', [
                    'assetKey' => $restore->getAssetKey(),
                    'snapshot' => $restore->getPoint(),
                    'connectionName' => $options['connectionName']
                ]);

            case RestoreType::EXPORT:
                return $this->router->generate('restore_exportimage', [
                    'agent' => $restore->getAssetKey(),
                    'point' => $restore->getPoint() . 'L'
                ]);

            case RestoreType::FILE:
                return $this->router->generate('restore_file_configure', [
                    'assetKeyName' => $restore->getAssetKey(),
                    'point' => $restore->getPoint() . 'L'
                ]);

            case RestoreType::ISCSI_RESTORE:
            case RestoreType::VOLUME_RESTORE:
                return $this->router->generate('restore_iscsi', [
                    'assetKey' => $restore->getAssetKey(),
                    'snapshot' => $restore->getPoint()
                ]);

            case RestoreType::ESX_VIRT:
            case RestoreType::ACTIVE_VIRT:
                $options = $restore->getOptions();
                return $this->router->generate('restore_virtualize_configure', [
                    'agentKey' => $restore->getAssetKey(),
                    'point' => $restore->getPoint() . 'L',
                    'hypervisor' => $options['connectionName'] ?? null
                ]);

            default:
                return '';
        }
    }
    
    /**
     * @param Agent $agent
     * @return array
     */
    public function getComparison(Agent $agent): array
    {
        $comparisons = $this->insightsService->getCurrentByAsset($agent);

        $data = [];

        foreach ($comparisons as $comparison) {
            $viewUrl = $this->router->generate('restore_insight', [
                'agentKey' => $agent->getKeyName(),
                'firstPoint' => $comparison->getFirstPoint(),
                'secondPoint' => $comparison->getSecondPoint()
            ]);

            $data[] = [
                'assetKey' => $agent->getKeyName(),
                'point1' => $comparison->getFirstPoint(),
                'point2' => $comparison->getSecondPoint(),
                'viewUrl' => $viewUrl
            ];
        }

        return $data;
    }
}
