<?php

namespace Datto\Config;

use Datto\App\Container\ServiceCollection;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Class to repair configuration values which may be missing/changed after an upgrade.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ConfigurationRepairService
{
    private DeviceLoggerInterface $logger;
    private ServiceCollection $tasks;

    /**
     * @param DeviceLoggerInterface $logger
     * @param ServiceCollection $tasks
     */
    public function __construct(DeviceLoggerInterface $logger, ServiceCollection $tasks)
    {
        $this->logger = $logger;
        $this->tasks = $tasks;
    }

    /**
     * Run all configured repair tasks
     */
    public function runTasks()
    {
        $errorCount = 0;
        $tasks = $this->tasks->getAll();
        $this->logger->info('CFG0011 Start running config repair tasks.', ['taskCount' => count($tasks)]);

        /** @var ConfigRepairTaskInterface $task */
        foreach ($tasks as $task) {
            $name = get_class($task);
            try {
                $this->logger->debug('CFG0007 Starting config repair task.', ['taskName' => $name]);
                $madeChanges = $task->run();
                $this->logger->debug(
                    'CFG0010 Finished config repair task.',
                    ['taskName' => $name, 'changed' => $madeChanges]
                );
            } catch (Throwable $e) {
                $errorCount++;
                $this->logger->error(
                    'CFG9999 Error occurred running task.',
                    ['taskName' => $name, 'exception' => $e]
                );
            }
        }

        if ($errorCount > 0) {
            $this->logger->error(
                'CFG0012 Finished running config repair tasks.',
                ['numTasks' => count($tasks), 'errorCount' => $errorCount]
            );
        } else {
            $this->logger->info(
                'CFG0013 Finished running config repair tasks without error.',
                ['numTasks' => count($tasks)]
            );
        }
    }
}
