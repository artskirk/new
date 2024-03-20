<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\CheckinService;
use Datto\Asset\Agent\DirectToCloud\ProtectedSystemConfigurationService;
use Datto\Asset\Retention;
use Datto\Backup\BackupContext;
use Datto\Config\DeviceConfig;
use Datto\DirectToCloud\Creation\Context;
use Datto\DirectToCloud\Creation\Service;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service for DTC Agent behaviors.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DirectToCloud
{

    /** @var AgentService */
    private $agentService;

    /** @var CheckinService */
    private $checkinService;

    /** @var ProtectedSystemConfigurationService */
    private $configurationService;

    /** @var Service */
    private $creationService;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        Service $creationService,
        CheckinService $checkinService,
        AgentService $agentService,
        DeviceLoggerInterface $logger,
        ProtectedSystemConfigurationService $configurationService,
        Filesystem $filesystem,
        ZfsDatasetService $zfsDatasetService,
        DeviceConfig $deviceConfig
    ) {
        $this->creationService = $creationService;
        $this->checkinService = $checkinService;
        $this->agentService = $agentService;
        $this->logger = $logger;
        $this->configurationService = $configurationService;
        $this->filesystem = $filesystem;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Create a direct-to-cloud agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @param string $agentUuid UUID of the physical agent.
     * @param string $hostname
     * @param array $retention
     * @param int $resellerId
     * @param bool $hasSubscription
     * @param string $operatingSystem
     * @return array
     */
    public function create(
        string $agentUuid,
        string $hostname,
        array $retention,
        int $resellerId,
        bool $hasSubscription,
        string $operatingSystem
    ): array {
        if (isset($retention['local'])) {
            $localRetention = $this->createRetention($retention['local']);
        } else {
            if ($this->deviceConfig->isAzureDevice()) {
                $localRetention = Retention::createDefaultAzureLocal();
            } else {
                $localRetention = Retention::createTimeBased(1);
            }
        }

        if (isset($retention['offsite'])) {
            $offsiteRetention = $this->createRetention($retention['offsite']);
        } else {
            $offsiteRetention = Retention::createTimeBased(1);
        }

        return $this->creationService->createAgentAsync(
            $agentUuid,
            $hostname,
            $localRetention,
            $offsiteRetention,
            $resellerId,
            false,
            $hasSubscription,
            $operatingSystem
        );
    }

    /**
     * Get the status of creating a DirectToCloud asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @param string $agentUuid UUID of the physical agent.
     * @return array
     */
    public function getCreateStatus(string $agentUuid)
    {
        return $this->creationService->getStatus($agentUuid);
    }

    /**
     * Note that the agent is active and save its host information.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid UUID of the physical agent.
     * @param array|null $metadata
     * @return bool
     */
    public function checkin(string $agentUuid, array $metadata = null)
    {
        $this->checkinService->checkin($agentUuid, $metadata);

        return true;
    }

    /**
     * The idea of this endpoint is to prepare the node for an agent to
     * interact with it, and return any information dtc needs from os2.
     *
     * For now, this just returns the log directory dtc should write logs to.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     */
    public function prepare(string $agentUuid) : array
    {
        $logPath = $this->filesystem->pathJoin(BackupContext::AGENTS_PATH, 'logs', $agentUuid);
        if (!$this->filesystem->exists($logPath)) {
            $dataset = $this->zfsDatasetService->getDataset(ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET);
            if (!$dataset || !$dataset->isMounted()) {
                throw new Exception("Could not create log directory because parent directory is not mounted.");
            }
            if (!$this->filesystem->mkdir($logPath, true, 0755)) {
                throw new Exception('Could not create Log directory : ' . $logPath);
            }
        }
        return [
            "logPath" => $logPath
        ];
    }

    /**
     * Reset the agent configuration request
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     * })
     *
     * @param string $assetKey
     * @param array $processedRequest
     * @param array $currentConfiguration
     * @return array
     */
    public function resetProtectedSystemAgentConfigRequest(
        string $assetKey,
        array $processedRequest,
        array $currentConfiguration
    ) {
        $agent = $this->agentService->get($assetKey);
        $request = $this->configurationService->resetConfigRequestIfUnchanged($agent, $processedRequest);

        $this->logger->debug("DTC0021 Current configuration for asset", [
            'assetKey' => $assetKey,
            'currentConfiguration' => $currentConfiguration
        ]);

        return [
            'agentKey' => $assetKey,
            'setProtectedSystemAgentConfigRequest' => $request
        ];
    }

    private function createRetention(array $retention): Retention
    {
        $valid = isset($retention['daily'])
            && isset($retention['weekly'])
            && isset($retention['monthly'])
            && isset($retention['maximum']);

        if (!$valid) {
            throw new \InvalidArgumentException(
                'Retention must include keys for daily, weekly, monthly, and maximum'
            );
        }

        return new Retention(
            $retention['daily'],
            $retention['weekly'],
            $retention['monthly'],
            $retention['maximum']
        );
    }
}
