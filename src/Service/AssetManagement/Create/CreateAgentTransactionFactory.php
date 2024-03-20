<?php

namespace Datto\Service\AssetManagement\Create;

use Datto\Service\AssetManagement\Create\Stages\CreateAgent;
use Datto\Service\AssetManagement\Create\Stages\CreateDataset;
use Datto\Service\AssetManagement\Create\Stages\PairAgent;
use Datto\Service\AssetManagement\Create\Stages\PreflightPairChecks;
use Datto\Service\AssetManagement\Create\Stages\PostCreate;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;

/**
 * Makes create agent transactions
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateAgentTransactionFactory
{
    /** @var PreflightPairChecks */
    private $preflightPairChecks;

    /** @var PairAgent */
    private $pairAgent;

    /** @var CreateDataset */
    private $createDataset;

    /** @var CreateAgent */
    private $createAgent;

    /** @var PostCreate */
    private $postCreate;

    public function __construct(
        PreflightPairChecks $preflightPairChecks,
        PairAgent $pairAgent,
        CreateDataset $createDataset,
        CreateAgent $createAgent,
        PostCreate $setupDefaults
    ) {
        $this->preflightPairChecks = $preflightPairChecks;
        $this->pairAgent = $pairAgent;
        $this->createDataset = $createDataset;
        $this->createAgent = $createAgent;
        $this->postCreate = $setupDefaults;
    }

    public function create(CreateAgentContext $createAgentContext, bool $isRepair = false): Transaction
    {
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $createAgentContext->getLogger(), $createAgentContext);

        $transaction->add($this->preflightPairChecks);
        $transaction->add($this->pairAgent);
        $transaction->addIf(!$isRepair, $this->createDataset);
        $transaction->add($this->createAgent);
        $transaction->add($this->postCreate);

        return $transaction;
    }
}
