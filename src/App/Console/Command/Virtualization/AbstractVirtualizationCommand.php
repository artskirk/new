<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Connection\Service\ConnectionService;
use Datto\Restore\Virtualization\ActiveVirtRestoreService;
use Exception;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractVirtualizationCommand extends AbstractCommand
{
    /** @var ActiveVirtRestoreService */
    protected $virtualizationRestoreService;

    /** @var AgentService */
    protected $agentService;

    /** @var ConnectionService */
    protected $connectionService;

    /** @var EncryptionService */
    protected $encryptionService;

    /** @var TempAccessService */
    protected $tempAccessService;

    /** @var RescueAgentService */
    protected $rescueAgentService;

    public function __construct(
        ActiveVirtRestoreService $virtualizationRestoreService,
        AgentService $agentService,
        ConnectionService $connectionService,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        RescueAgentService $rescueAgentService
    ) {
        parent::__construct();

        $this->virtualizationRestoreService = $virtualizationRestoreService;
        $this->agentService = $agentService;
        $this->connectionService = $connectionService;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->rescueAgentService = $rescueAgentService;
    }

    /**
     * @param string $assetKey
     * @return int
     */
    protected function getLatestSnapshot(string $assetKey): int
    {
        $asset = $this->agentService->get($assetKey);
        $lastPoint = $asset->getLocal()->getRecoveryPoints()->getLast();

        if ($lastPoint === null) {
            throw new Exception("No recovery points available for $assetKey");
        }

        return $lastPoint->getEpoch();
    }

    /**
     * @return string
     */
    protected function getDefaultConnectionName(): string
    {
        return $this->connectionService->find()->getName();
    }
}
