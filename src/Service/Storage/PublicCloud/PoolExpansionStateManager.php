<?php

namespace Datto\Service\Storage\PublicCloud;

use Datto\App\Console\Command\Storage\PublicCloud\PublicCloudExpandCommand;
use Datto\App\Console\SnapctlApplication;
use Datto\Config\DeviceState;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Utility\Screen;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service class for starting the expansion process and managing its state.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class PoolExpansionStateManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const POOL_EXPANSION_SCREEN_NAME = 'publicCloudPoolExpansion';

    private Screen $screenService;
    private DeviceState $deviceState;
    private DateTimeService $dateTimeService;
    private Collector $collector;

    public function __construct(
        Screen $screenService,
        DeviceState $deviceState,
        DateTimeService $dateTimeService,
        Collector $collector
    ) {
        $this->screenService = $screenService;
        $this->deviceState = $deviceState;
        $this->dateTimeService = $dateTimeService;
        $this->collector = $collector;
    }

    /**
     * Run the command to expand the data pool in a screen.
     *
     * @param int $diskLun
     * @param bool $resize Specify if the disk has been resized
     *
     * @return bool True if the screen was successfully started, False otherwise
     */
    public function startPoolExpansionBackground(int $diskLun, bool $resize): bool
    {
        $this->logger->info('ESM0000 Data pool expansion request received', [
            'requestedLun' => $diskLun,
            'resize' => $resize
        ]);

        if ($this->screenService->isScreenRunning(self::POOL_EXPANSION_SCREEN_NAME)) {
            $this->logger->info('ESM0001 Data pool expansion is already running', [
                'screenName' => self::POOL_EXPANSION_SCREEN_NAME
            ]);

            return true;
        }

        $command = [
            SnapctlApplication::EXECUTABLE_NAME,
            PublicCloudExpandCommand::getDefaultName(),
            $diskLun
        ];

        if ($resize) {
            $command[] = '--resize';
        }

        $started = $this->screenService->runInBackground(
            $command,
            self::POOL_EXPANSION_SCREEN_NAME
        );

        if (!$started) {
            $this->logger->error('ESM0002 Failed to start screen for data pool expansion request');
        }

        return $started;
    }

    public function getPoolExpansionState(): PoolExpansionState
    {
        $currentState = new PoolExpansionState();
        $this->deviceState->loadRecord($currentState);
        return $currentState;
    }

    public function setRunning()
    {
        $this->logger->debug('ESM0003 Storage pool expansion state changed to running');
        $this->setState(PoolExpansionState::RUNNING);
    }

    public function setSuccess()
    {
        $this->logger->debug('ESM0004 Storage pool expansion state changed to success');
        $this->setState(PoolExpansionState::SUCCESS);
    }

    public function setFailed(bool $logFailure = true)
    {
        if ($logFailure) {
            $this->logger->error('ESM0005 Storage pool expansion failed internally');
        }
        $this->setState(PoolExpansionState::FAILED);
    }

    private function setState(string $newState)
    {
        $currentPoolExpansionState = $this->getPoolExpansionState();
        $this->validateStateTransition($currentPoolExpansionState, $newState);
        $this->sendStateChangeMetricsIfNecessary($currentPoolExpansionState, $newState);

        $stateToSave = new PoolExpansionState();
        $stateToSave->setState($newState);
        $stateToSave->setStateChangedAt($this->dateTimeService->getTime());
        $this->deviceState->saveRecord($stateToSave);
    }

    private function validateStateTransition(PoolExpansionState $currentPoolExpansionState, string $newState)
    {
        $currentState = $currentPoolExpansionState->getState();
        if ($currentState === $newState) {
            $this->logger->warning('ESM0006 Set state called when already in state', ['newState' => $newState]);
        }
        $this->validateRunningStateNotSkipped($currentState, $newState);
    }

    private function sendStateChangeMetricsIfNecessary(PoolExpansionState $currentPoolExpansionState, string $newState)
    {
        $currentState = $currentPoolExpansionState->getState();
        if ($currentState !== PoolExpansionState::SUCCESS && $newState === PoolExpansionState::SUCCESS) {
            $duration = $this->dateTimeService->getElapsedTime($currentPoolExpansionState->getStateChangedAt());
            $this->collector->timing(Metrics::STATISTIC_ZFS_POOL_EXPANSION_PROCESS_DURATION, $duration);
        }
    }

    private function validateRunningStateNotSkipped(string $currentState, string $newState)
    {
        $isFailedToSuccess = $currentState === PoolExpansionState::FAILED && $newState === PoolExpansionState::SUCCESS;
        $isSuccessToFailed = $currentState === PoolExpansionState::SUCCESS && $newState === PoolExpansionState::FAILED;
        if ($isFailedToSuccess || $isSuccessToFailed) {
            $this->logger->error(
                'ESM0007 invalid state transition',
                [
                    'currentState' => $currentState,
                    'newState' => $newState
                ]
            );
            throw new Exception("Invalid state transition from $currentState to $newState");
        }
    }
}
