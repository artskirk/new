<?php

namespace Datto\Restore\PushFile;

use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\PushFile\Stages\ClonePushFileRestoreStage;
use Datto\Restore\PushFile\Stages\CreateMercuryTargetStage;
use Datto\Restore\PushFile\Stages\CreateZipStage;
use Datto\Restore\PushFile\Stages\TransferRestoreDataStage;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionException;
use Datto\System\Transaction\TransactionFailureType;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Main entry point for managing push file restores.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class PushFileRestoreService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentApiFactory $agentApiFactory;

    private AssetService $assetService;

    private RestoreService $restoreService;

    private CreateZipStage $createZipStage;

    private ClonePushFileRestoreStage $clonePushFileRestoreStage;

    private CreateMercuryTargetStage $createMercuryTargetStage;

    private TransferRestoreDataStage $transferRestoreDataStage;

    public function __construct(
        AgentApiFactory $agentApiFactory,
        AssetService $assetService,
        RestoreService $restoreService,
        ClonePushFileRestoreStage $clonePushFileRestoreStage,
        CreateZipStage $createZipStage,
        CreateMercuryTargetStage $createMercuryTargetStage,
        TransferRestoreDataStage $transferRestoreDataStage
    ) {
        $this->assetService = $assetService;
        $this->restoreService = $restoreService;
        $this->clonePushFileRestoreStage = $clonePushFileRestoreStage;
        $this->createZipStage = $createZipStage;
        $this->createMercuryTargetStage = $createMercuryTargetStage;
        $this->transferRestoreDataStage = $transferRestoreDataStage;
        $this->agentApiFactory = $agentApiFactory;
    }

    /**
     * Send the files back to the protected machine
     * @param string[] $files
     */
    public function pushFiles(
        string $assetKey,
        int $snapshot,
        PushFileRestoreType $pushFileRestoreType,
        string $destination,
        bool $keepBoth,
        bool $restoreAcls,
        array $files
    ): Restore {
        $this->logger->setAssetContext($assetKey);

        if (empty($files)) {
            throw new TransactionException('Push file restore failed because no files were provided.');
        }

        $context = $this->createFileRestoreContext(
            $assetKey,
            $snapshot,
            $pushFileRestoreType,
            $destination,
            $keepBoth,
            $restoreAcls,
            $files
        );

        $transaction = $this->createTransaction($context);
        try {
            $transaction->commit();
            $this->logger->info('PFR0001 Push file restore completed.');
            return $context->getRestore();
        } catch (TransactionException $e) {
            $this->logger->error('PFR0002 Push file restore failed.', ['error' => $e->getMessage()]);
            throw $e->getPrevious() ?? $e;
        }
    }

    private function createTransaction(PushFileRestoreContext $context): Transaction
    {
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $this->logger, $context);
        $transaction
            ->add($this->clonePushFileRestoreStage)
            ->add($this->createZipStage)
            ->add($this->createMercuryTargetStage)
            ->add($this->transferRestoreDataStage);

        return $transaction;
    }

    /**
     * @param string[] $files
     */
    private function createFileRestoreContext(
        string $assetKey,
        int $snapshot,
        PushFileRestoreType $pushFileRestoreType,
        string $destination,
        bool $keepBoth,
        bool $restoreAcls,
        array $files
    ): PushFileRestoreContext {
        $restore = $this->restoreService->find($assetKey, $snapshot, RestoreType::FILE);

        if (is_null($restore)) {
            throw new Exception("No file restore set up for snapshot \"$snapshot\"");
        }

        $asset = $this->assetService->get($assetKey);

        // PFR must take place on an agent
        if (!$asset->isType(AssetType::AGENT)) {
            $assetType = $asset->getType();
            throw new Exception("Invalid asset type: \"$assetType\". Push file restore can only be used on agents.");
        }
        
        // PFR must take place on a Datto backup agent
        $agentApi = $this->agentApiFactory->createFromAgent($asset);
        if (!$agentApi instanceof DattoAgentApi || ($agentApi instanceof AgentlessProxyApi)) {
            throw new Exception("Invalid agent type. A Datto backup agent is required for push file restore.");
        }

        // PFR must take place on a Datto backup agent with the PFR feature enabled
        if (!$agentApi->featureExists(DattoAgentApi::AGENT_FEATURE_PUSH_FILE_RESTORE)) {
            throw new Exception("Unable to perform push file restore, because it is not supported by the agent.");
        }

        $context = new PushFileRestoreContext(
            $asset,
            $restore,
            $pushFileRestoreType,
            $destination,
            $keepBoth,
            $restoreAcls,
            $files,
            $agentApi
        );

        return $context;
    }
}
