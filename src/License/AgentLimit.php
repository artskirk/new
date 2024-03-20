<?php

namespace Datto\License;

use Datto\Asset\Agent\AgentService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Billing\ServicePlanService;
use Datto\Config\ShmConfig;
use Datto\Common\Resource\PosixHelper;
use Datto\Utility\File\LockFactory;
use Exception;

/**
 * Class AgentLimit access agent-related limitations of the device (number paired, number backed up, etc.)
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class AgentLimit
{
    private const MAXIMUM_ALLOWED_CONCURRENT_BACKUP_LIMIT = 10;

    public const UNLIMITED = 10000;// to simplify the math; this is more agents than a device could reasonably manage

    private AgentService $agentService;
    private DeviceConfig $deviceConfig;
    private ServicePlanService $servicePlanService;
    private ShmConfig $shmConfig;
    private LockFactory $lockFactory;
    private PosixHelper $posixHelper;
    private AgentConfigFactory $agentConfigFactory;

    public function __construct(
        AgentService $agentService = null,
        DeviceConfig $deviceConfig = null,
        ServicePlanService $servicePlanService = null,
        ShmConfig $shmConfig = null,
        LockFactory $lockFactory = null,
        PosixHelper $posixHelper = null,
        AgentConfigFactory $agentConfigFactory = null
    ) {
        $this->agentService = $agentService ?? new AgentService();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->servicePlanService = $servicePlanService ?? new ServicePlanService($this->deviceConfig);
        $this->shmConfig = $shmConfig ?? new ShmConfig();
        $this->lockFactory = $lockFactory ?? new LockFactory();
        $this->posixHelper = $posixHelper ?? new PosixHelper(new ProcessFactory());
        $this->agentConfigFactory = $agentConfigFactory ?? new AgentConfigFactory();
    }

    /**
     * Check whether agent(s) may be added, assuming they will be unpaused
     * This takes into account agents that are in the process of being added.
     *
     * @param int $newAgentCount check whether this number of agents may be added (default 1)
     * @return bool whether this number of unpaused agents may be added
     */
    public function canAddAgents(int $newAgentCount = 1): bool
    {
        return $newAgentCount <= $this->getMaxAdditionalAgents();
    }

    /**
     * Reserve a spot in the agent limit for pairing a specific agent
     * This keeps track so we can prevent starting more pairing processes than the number of agents we're allowed to add
     *
     * When the agent has been created or fails, releaseReservation() should be called
     */
    public function reserveAgent(string $keyName)
    {
        if ($this->getTotalAgentLimit() === self::UNLIMITED) {
            return; // do nothing because we don't care about reservations when there is no limit
        }

        try {
            $this->shmConfig->touch('agentLimitSlots');
            $lock = $this->lockFactory->getProcessScopedLock($this->shmConfig->getKeyFilePath('agentLimitSlots'));
            $lock->assertExclusiveAllowWait(30);

            $reserved = $this->getReserved();

            if (!$this->canAddAgents()) {
                throw new Exception('Agent limit has been reached.');
            }

            // reserve a spot for the agent we want to add
            $reserved[$keyName] = $this->posixHelper->getCurrentProcessId();
            $this->shmConfig->set('agentLimitSlots', json_encode($reserved));
        } finally {
            if (isset($lock)) {
                $lock->unlock();
            }
        }
    }

    /**
     * Release our reserved spot in the agent limit for an agent.
     * Call this when the agent is either created successfully or can't be created
     */
    public function releaseReservation(string $keyName)
    {
        if ($this->getTotalAgentLimit() === self::UNLIMITED) {
            return; // do nothing because we don't care about reservations when there is no limit
        }

        try {
            $this->shmConfig->touch('agentLimitSlots');
            $lock = $this->lockFactory->getProcessScopedLock($this->shmConfig->getKeyFilePath('agentLimitSlots'));
            $lock->assertExclusiveAllowWait(30);

            $reserved = $this->getReserved();

            // remove the reservation for our agent
            unset($reserved[$keyName]);
            $this->shmConfig->set('agentLimitSlots', json_encode($reserved));
        } finally {
            if (isset($lock)) {
                $lock->unlock();
            }
        }
    }

    /**
     * @return bool whether a single existing agent may be unpaused
     */
    public function canUnpauseAgent(): bool
    {
        $unpausedAgentLimit = $this->getUnpausedAgentLimit();
        if ($unpausedAgentLimit === self::UNLIMITED) {
            return true;
        }

        $allAgents = $this->agentService->getAllActiveLocalKeyNames();
        $reserved = $this->getReserved($allAgents);
        $unpausedAgentCount = $this->countUnpausedAgents($allAgents) + count($reserved);

        return $unpausedAgentCount < $unpausedAgentLimit;
    }

    /**
     * @return bool whether all agents on the device may be unpaused
     */
    public function canUnpauseAllAgents(): bool
    {
        $unpausedAgentLimit = $this->getUnpausedAgentLimit();
        if ($unpausedAgentLimit === self::UNLIMITED) {
            return true;
        }

        $allAgents = $this->agentService->getAllActiveLocalKeyNames();
        $reserved = $this->getReserved($allAgents);
        $totalAgentCount = count($allAgents) + count($reserved);

        return $totalAgentCount <= $unpausedAgentLimit;
    }

    /**
     * @return int the highest number that the concurrent backup limit may be set to
     */
    public function getConcurrentBackupLimit(): int
    {
        $unpausedAgentLimit = $this->getUnpausedAgentLimit();
        if ($unpausedAgentLimit > self::MAXIMUM_ALLOWED_CONCURRENT_BACKUP_LIMIT) {
            return self::MAXIMUM_ALLOWED_CONCURRENT_BACKUP_LIMIT;
        }
        return $unpausedAgentLimit;
    }

    /**
     * Gets total agent limit by checking service plan, then model
     *
     * @return int maximum number of agents that may be paired with the device at one time (some may have to be paused)
     */
    public function getTotalAgentLimit(): int
    {
        if ($this->servicePlanService->get()->getServicePlanName() === ServicePlanService::PLAN_TYPE_FREE) {
            return 3;
        }

        switch ($this->deviceConfig->get('model')) {
            case 'ALTO':
            case 'ALTO2':
                return 4;
            case 'S3X1':
                return 2;
            case 'S3X2':
                return 3;
            case DeviceConfig::MODEL_ALTO3A2000:
            case DeviceConfig::MODEL_ALTO3A2:
            case 'ALTO4':
            case 'S3X4':
                return 5;
            default:
                return self::UNLIMITED;
        }
    }

    /**
     * Gets unpaused agent limit by checking service plan, then model
     *
     * @return int maximum number of agents that may be unpaused (taking backups) on the device at one time
     */
    public function getUnpausedAgentLimit(): int
    {
        if ($this->servicePlanService->get()->getServicePlanName() === ServicePlanService::PLAN_TYPE_FREE) {
            return 2;
        }

        switch ($this->deviceConfig->get('model')) {
            case 'ALTO':
            case 'ALTO2':
            case DeviceConfig::MODEL_ALTO3A2000:
            case DeviceConfig::MODEL_ALTO3A2:
            case 'ALTO4':
            case 'S3X4':
                return 4;
            case 'S3X1':
                return 1;
            case 'S3X2':
                return 2;
            default:
                return self::UNLIMITED;
        }
    }

    /**
     * Gets maximum number agents that may be added to this device
     *
     * @return int maximum number of agents that may be added to this agent
     */
    public function getMaxAdditionalAgents(): int
    {
        $totalAgentLimit = $this->getTotalAgentLimit();
        if ($totalAgentLimit === self::UNLIMITED) {
            return self::UNLIMITED;
        }

        $allAgents = $this->agentService->getAllActiveLocalKeyNames();
        $reserved = $this->getReserved($allAgents);
        $totalAgentCount = count($allAgents) + count($reserved);
        $unpausedAgentCount = $this->countUnpausedAgents($allAgents) + count($reserved);
        $unpausedAgentLimit = $this->getUnpausedAgentLimit();
        $agentLimitExceeded = $totalAgentCount > $totalAgentLimit || $unpausedAgentCount > $unpausedAgentLimit;

        if ($agentLimitExceeded) {
            return 0;
        }

        return min($unpausedAgentLimit - $unpausedAgentCount, $totalAgentLimit - $totalAgentCount);
    }

    /**
     * @param string[] $agentKeyNames
     */
    private function countUnpausedAgents(array $agentKeyNames): int
    {
        $unpausedCount = 0;
        foreach ($agentKeyNames as $keyName) {
            $agentConfig = $this->agentConfigFactory->create($keyName);
            if (!$agentConfig->isPaused()) {
                $unpausedCount++;
            }
        }
        return $unpausedCount;
    }

    /**
     * @param string[] $agentsToFilter
     */
    private function getReserved(array $agentsToFilter = []): array
    {
        $reserved = json_decode($this->shmConfig->get('agentLimitSlots'), true);

        if (!is_array($reserved)) {
            $reserved = [];
        }

        $reservedValidated = [];
        foreach ($reserved as $agentKey => $pid) {
            if ($this->posixHelper->isProcessRunning($pid)) {
                $reservedValidated[$agentKey] = $pid;
            }
        }

        // Filter out any agents that are already created to avoid double counting
        foreach ($agentsToFilter as $keyName) {
            unset($reservedValidated[$keyName]);
        }
        return $reservedValidated;
    }
}
