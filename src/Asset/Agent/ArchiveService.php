<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Asset\AssetInfoSyncService;
use Datto\Backup\BackupCancelManager;
use Datto\Utility\Cloud\SpeedSync;
use Datto\Config\AgentConfig;
use Datto\Config\AgentStateFactory;
use Datto\License\ShadowProtectLicenseManagerFactory;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service to archive agents.
 * This service supports both normal Agents and Rescue Agents
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class ArchiveService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private RescueAgentService $rescueAgentService;
    private ShadowProtectLicenseManagerFactory $licenseManagerFactory;
    private AgentStateFactory $agentStateFactory;
    private BackupCancelManager $backupCancelManager;
    private SpeedSync $speedSync;
    private AssetInfoSyncService $assetInfoSyncService;

    public function __construct(
        AgentService $agentService,
        RescueAgentService $rescueAgentService,
        ShadowProtectLicenseManagerFactory $licenseManagerFactory,
        AgentStateFactory $agentStateFactory,
        BackupCancelManager $backupCancelManager,
        SpeedSync $speedSync,
        AssetInfoSyncService $assetInfoSyncService
    ) {
        $this->agentService = $agentService;
        $this->rescueAgentService = $rescueAgentService;
        $this->licenseManagerFactory = $licenseManagerFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->speedSync = $speedSync;
        $this->assetInfoSyncService = $assetInfoSyncService;
    }

    public function archive(string $agentKey): void
    {
        $this->logger->setAssetContext($agentKey);

        $agent = $this->agentService->get($agentKey);
        if ($agent->getOriginDevice()->isReplicated() && !$agent->isRescueAgent()) {
            throw new Exception('Replicated agents cannot be archived');
        }

        $this->logger->info('ARS0002 Achiving agent'); // log code is used by device-web see DWI-2252

        $this->backupCancelManager->cancelRunningAndSuspend($agent);

        $this->speedSync->halt($agent->getDataset()->getZfsPath());

        try {
            if ($agent->isRescueAgent() && !$this->rescueAgentService->isArchived($agentKey)) {
                $this->rescueAgentService->archive($agentKey);
                // archive() changed the agent, reload so we don't clobber the changes when we save the agent below
                $agent = $this->agentService->get($agentKey);
            }
            $agent->getLocal()->setArchived(true);
            $this->agentService->save($agent);

            $shouldReleaseLicense =
                !$agent->isRescueAgent() &&
                $agent->getPlatform() === AgentPlatform::SHADOWSNAP();
            if ($shouldReleaseLicense) {
                try {
                    $licenseManager = $this->licenseManagerFactory->create($agentKey);
                    $licenseManager->releaseUnconditionally();
                } catch (\Exception $e) {
                    $this->logger->warning(
                        'ARS0001 Unable to release shadowprotect license for archived agent',
                        ['exception' => $e]
                    );
                }
            }
            
            $agent->getDataset()->delete();
            $agentState = $this->agentStateFactory->create($agentKey);
            $agentState->clear(CertificateUpdateService::TRUSTED_ROOT_HASH_KEY);
            $agentState->clear(CertificateUpdateService::CERT_EXPIRATION_KEY);
        } finally {
            $this->backupCancelManager->resumeBackups($agent);
            $this->logger->info(
                "ARS0003 Syncing agent info with device-web after archiving agent",
                ['agentUuid' => $agentKey]
            );
            $this->assetInfoSyncService->sync($agentKey);
        }
    }

    public function isArchived(string $agentKeyName): bool
    {
        // Note: this implementation is optimized for speed
        $agentConfig = new AgentConfig($agentKeyName);
        if ($agentConfig->has('archived')) {
            return true;
        } elseif ($agentConfig->isRescueAgent()) {
            return $this->rescueAgentService->isArchived($agentKeyName);
        }
        return false;
    }
}
