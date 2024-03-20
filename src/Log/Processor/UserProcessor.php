<?php

namespace Datto\Log\Processor;

use Datto\Log\LogRecord;
use Datto\System\ExecutionEnvironmentService;
use Datto\User\WebUser;
use Throwable;

/**
 * Adds the user to log records.
 * Required if using an Asset* handler or formatter.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class UserProcessor
{
    const DEFAULT_USER = '(CLI)';

    /** @var ExecutionEnvironmentService */
    private $executionEnvironment;

    /** @var WebUser */
    private $webUser;

    /** @var string */
    private $username;

    public function __construct(ExecutionEnvironmentService $executionEnvironment, WebUser $webUser)
    {
        $this->executionEnvironment = $executionEnvironment;
        $this->webUser = $webUser;
    }

    /**
     * Processes the given record.
     *
     * @param array $record
     * @return array
     */
    public function __invoke($record)
    {
        if (!isset($this->username)) {
            $this->updateUser();
        }

        $logRecord = new LogRecord($record);
        $logRecord->setUser($this->username);
        return $logRecord->toArray();
    }

    /**
     * Get the current user or set to CLI this was initiated from the command line.
     */
    private function updateUser()
    {
        try {
            if (!$this->executionEnvironment->isCli()) {
                $username = $this->webUser->getUserName();
            }

            $this->username = $username ?? static::DEFAULT_USER;
        } catch (Throwable $e) {
            // don't allow exception here to prevent logging
        }
    }
}
