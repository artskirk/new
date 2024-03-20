<?php

namespace Datto\Backup;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Config\AgentConfigFactory;
use Datto\DirectToCloud\DirectToCloudCommander;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Datto\Service\Alert\AlertService;
use Datto\Utility\Systemd\Systemctl;
use Psr\Log\LoggerAwareInterface;

/**
 * Creates BackupManager objects
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupManagerFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var BackupTransactionFactory */
    private $backupTransactionFactory;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var BackupCancelManager */
    private $backupCancelManager;

    /** @var AlertManager */
    private $alertManager;

    /** @var BackupEventService */
    private $backupEventService;

    /** @var DirectToCloudCommander */
    private $directToCloudCommander;

    /** @var Systemctl */
    private $systemctl;

    /** @var SnapshotStatusService */
    private $snapshotStatusService;

    /** @var Sleep */
    private $sleep;

    /** @var AgentConnectivityService */
    private $agentConnectivityService;

    /** @var AssetService */
    private $assetService;

    /** @var RepairService */
    private $repairService;

    /** @var AlertService */
    private $alertService;

    public function __construct(
        BackupTransactionFactory $backupTransactionFactory,
        AgentConfigFactory $agentConfigFactory,
        BackupCancelManager $backupCancelManager,
        AlertManager $alertManager,
        BackupEventService $backupEventService,
        DirectToCloudCommander $directToCloudCommander,
        Systemctl $systemctl,
        SnapshotStatusService $snapshotStatusService,
        Sleep $sleep,
        AgentConnectivityService $agentConnectivityService,
        AssetService $assetService,
        RepairService $repairService,
        AlertService $alertService
    ) {
        $this->backupTransactionFactory = $backupTransactionFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->alertManager = $alertManager;
        $this->backupEventService = $backupEventService;
        $this->directToCloudCommander = $directToCloudCommander;
        $this->systemctl = $systemctl;
        $this->snapshotStatusService = $snapshotStatusService;
        $this->sleep = $sleep;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->assetService = $assetService;
        $this->repairService = $repairService;
        $this->alertService = $alertService;
    }

    public function create(Asset $asset): BackupManager
    {
        $this->logger->setAssetContext($asset->getKeyName());
        $backupStatus = new BackupStatusService($asset->getKeyName(), $this->logger);
        $backupManager = new BackupManager(
            $asset,
            $this->logger,
            $this->alertManager,
            $this->backupTransactionFactory,
            $backupStatus,
            $this->agentConfigFactory,
            $this->backupCancelManager,
            $this->backupEventService,
            $this->directToCloudCommander,
            $this->systemctl,
            $this->snapshotStatusService,
            $this->sleep,
            $this->agentConnectivityService,
            $this->assetService,
            $this->repairService,
            $this->alertService
        );
        return $backupManager;
    }
}
