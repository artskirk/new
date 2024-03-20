<?php

namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DmCryptManager;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\MountLoopHelper;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\Insight\InsightStages\CloneSnapshotStage;
use Datto\Restore\Insight\InsightStages\MftDumpStage;
use Datto\Restore\Insight\InsightStages\MountClonesStage;
use Datto\Restore\Insight\InsightStages\VerifyIntegrityStage;
use Datto\System\MountManager;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;

/**
 * Create a backup inspection process
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightsFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AssetCloneManager */
    private $cloneManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var MountManager */
    private $mountManager;

    /** @var MountLoopHelper */
    private $mountLoopHelper;

    private ProcessFactory $processFactory;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var InsightsResultsService */
    private $resultsService;

    /** @var AgentShmConfigFactory */
    private $agentShmConfigFactory;

    /** @var LoopManager */
    private $loopManager;

    /** @var DmCryptManager */
    private $dmCryptManager;

    public function __construct(
        AssetCloneManager $cloneManager,
        Filesystem $filesystem,
        MountManager $mountManager,
        MountLoopHelper $mountLoopHelper,
        ProcessFactory $processFactory,
        EncryptionService $encryptionService,
        InsightsResultsService $resultsService,
        AgentShmConfigFactory $agentShmConfigFactory,
        LoopManager $loopManager,
        DmCryptManager $dmCryptManager
    ) {
        $this->cloneManager = $cloneManager;
        $this->filesystem = $filesystem;
        $this->mountManager = $mountManager;
        $this->mountLoopHelper = $mountLoopHelper;
        $this->processFactory = $processFactory;
        $this->encryptionService = $encryptionService;
        $this->resultsService = $resultsService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->loopManager = $loopManager;
        $this->dmCryptManager = $dmCryptManager;
    }

    public function create(Agent $agent, int $firstPoint, int $secondPoint): Transaction
    {
        $keyName = $agent->getKeyName();

        $insight = new BackupInsight($agent, $firstPoint, $secondPoint);
        $this->logger->setAssetContext($keyName);
        $verificationTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE());
        $verificationTransaction
            ->add(new CloneSnapshotStage(
                $insight,
                $this->cloneManager,
                $this->filesystem,
                $this->agentShmConfigFactory,
                $this->logger
            ))
            ->add(new MountClonesStage(
                $insight,
                $this->cloneManager,
                $this->filesystem,
                $this->mountManager,
                $this->mountLoopHelper,
                $this->processFactory,
                $this->encryptionService,
                $this->logger,
                $this->agentShmConfigFactory,
                $this->resultsService
            ))
            ->add(new VerifyIntegrityStage(
                $insight,
                $this->cloneManager,
                $this->filesystem,
                $this->agentShmConfigFactory,
                $this->logger
            ))
            ->add(new MftDumpStage(
                $insight,
                $this->cloneManager,
                $this->filesystem,
                $this->processFactory,
                $this->logger,
                $this->resultsService,
                $this->agentShmConfigFactory,
                $this->loopManager,
                $this->encryptionService,
                $this->dmCryptManager
            ));
        
        return $verificationTransaction;
    }
}
