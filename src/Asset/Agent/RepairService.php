<?php

namespace Datto\Asset\Agent;

use Datto\Agent\RepairHandler;
use Datto\AppKernel;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Service\AssetManagement\Repair\RepairAgent;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Does a repair for all agents
 *
 * TODO: When newPair becomes the default, move autorepair to RepairAgent, remove this class, and directly call RepairAgent anywhere this is currently called
 * @author John Fury Christ <jchrist@datto.com>
 */
class RepairService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const REPAIR_ATTEMPT_DELAY_IN_SECONDS = 5400;  // 90 minutes
    const MAXIMUM_REPAIR_ATTEMPTS = 10;

    private RepairHandler $repairHandler;
    private AgentService $agentService;
    private AgentApiFactory $agentApiFactory;
    private DateTimeService $dateTimeService;
    private ?RepairAgent $repairAgent;
    private FeatureService $featureService;
    private AgentStateFactory $agentStateFactory;

    public function __construct(
        ?RepairHandler $repairHandler = null,
        ?AgentService $agentService = null,
        ?AgentApiFactory $agentApiFactory = null,
        ?DateTimeService $dateTimeService = null,
        ?FeatureService $featureService = null,
        ?RepairAgent $repairAgent = null,
        ?AgentStateFactory $agentStateFactory = null
    ) {
        $this->repairHandler = $repairHandler ?: new RepairHandler(); // RepairHandler is in web/lib and not available from the container
        $this->agentService = $agentService ?: new AgentService();
        $this->agentApiFactory = $agentApiFactory ?: new AgentApiFactory();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->featureService = $featureService ?? new FeatureService();
        $this->repairAgent = $repairAgent ?? AppKernel::getBootedInstance()->getContainer()->get(RepairAgent::class);
        $this->agentStateFactory = $agentStateFactory ?? new AgentStateFactory();
    }

    /**
     * Repairs an agent
     * @param string $agentName
     * @return array
     */
    public function repair(string $agentName): array
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_NEW_REPAIR)) {
            $this->repairAgent->repair($agentName);
        } else {
            $this->repairHandler->setLogger($this->logger);
            $repairResult = $this->repairHandler->repair($agentName);
            if ($repairResult != RepairHandler::REPAIR_SUCCESS) {
                throw new Exception('Cannot run repair for ' . $agentName, $repairResult);
            }
        }

        return ['agentName' => $agentName];
    }

    /**
     * Repair communication with an agent while respecting the repair attempt limitations.
     *
     * @param string $assetKeyName
     * @return bool True if communication to the agent could be established, False otherwise
     */
    public function autoRepair(string $assetKeyName): bool
    {
        $this->repairHandler->setLogger($this->logger);
        $this->logger->setAssetContext($assetKeyName);

        try {
            $agent = $this->agentService->get($assetKeyName);
            $agentApi = $this->agentApiFactory->createFromAgent($agent);
            $agentInfo = $agentApi->getHost();

            if (is_array($agentInfo)) {
                $this->logger->debug('BAK2316 Skipping auto-repair, agent communication is not broken');
                return true;
            }
        } catch (Throwable $throwable) {
            $this->logger->error('BAK2317 Could not reach agent, attempting auto-repair', ['exception' => $throwable]);
        }

        $agentState = $this->agentStateFactory->create($assetKeyName);
        if ($this->allowedToRepair($agentState)) {
            $this->logger->info('BAK2310 Performing auto-repair');
            $this->incrementRetryCount($agentState);

            try {
                $this->repair($assetKeyName);
                $this->logger->info('BAK2312 Agent communication repaired');
                return true;
            } catch (Throwable $e) {
                $this->logger->error('BAK2313 Agent auto-repair failed', ['exception' => $e]);
                return false;
            }
        }

        return false;
    }

    private function getRetryCountData(AgentState $agentState): array
    {
        $countData = ['count' => 0, 'timestamp' => 0];
        if ($agentState->has(AgentState::KEY_AUTOREPAIR_RETRYCOUNT)) {
            $fileContents = $agentState->getRaw(AgentState::KEY_AUTOREPAIR_RETRYCOUNT, true);
            $countData = json_decode($fileContents, true) ?? $countData;
        }

        return $countData;
    }

    private function incrementRetryCount(AgentState $agentState): void
    {
        $count = $this->getRetryCountData($agentState)['count'] ?? 0;
        $count += 1;

        $outData = ['count' => $count, 'timestamp' => $this->dateTimeService->getTime()];
        $agentState->setRaw(AgentState::KEY_AUTOREPAIR_RETRYCOUNT, json_encode($outData));
    }

    private function resetRetryCount(AgentState $agentState): void
    {
        $agentState->clear(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);
    }

    private function allowedToRepair(AgentState $agentState): bool
    {
        if (!$agentState->has(AgentState::KEY_AUTOREPAIR_RETRYCOUNT)) {
            return true;
        }

        $retryData = $this->getRetryCountData($agentState);
        $lastAttempt = $retryData['timestamp'];
        $timeAgo = $this->dateTimeService->getTime() - $lastAttempt;

        if ($timeAgo <= self::REPAIR_ATTEMPT_DELAY_IN_SECONDS) {
            $this->logger->debug('BAK2315 Skipping auto-repair, delay not yet reached');
            return false;
        }

        if ($timeAgo > DateTimeService::SECONDS_PER_DAY) {
            $this->logger->debug('BAK2311 24-hours since last auto-repair, resetting daily attempts');
            $this->resetRetryCount($agentState);
            return true;
        }

        $count = $retryData['count'];
        if ($count >= self::MAXIMUM_REPAIR_ATTEMPTS) {
            $this->logger->debug('BAK2314 Skipping auto-repair, maximum daily attempts reached');
            return false;
        }

        return true;
    }
}
