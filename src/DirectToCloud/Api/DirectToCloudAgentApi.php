<?php

namespace Datto\DirectToCloud\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentRequest;
use Datto\Asset\Agent\Api\BackupApiContext;
use Datto\Asset\Agent\Certificate\CertificateSet;
use Datto\Asset\Agent\Job\BackupJobStatus;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class DirectToCloudAgentApi implements AgentApi
{
    /** @var array */
    private $hostInfo;

    /**
     * @param array $hostInfo
     */
    public function __construct(array $hostInfo)
    {
        $this->hostInfo = $hostInfo;
    }

    public function getPlatform(): AgentPlatform
    {
        return AgentPlatform::DIRECT_TO_CLOUD();
    }

    public function initialize()
    {
        // Do nothing.
    }

    public function cleanup()
    {
        // Do nothing.
    }

    public function pairAgent(string $agentKeyName = null)
    {
        self::throwNotImplemented();
    }

    public function sendAgentPairTicket(array $ticket)
    {
        self::throwNotImplemented();
    }

    public function startBackup(BackupApiContext $backupContext)
    {
        self::throwNotImplemented();
    }

    public function cancelBackup(string $jobID)
    {
        self::throwNotImplemented();
    }

    public function updateBackupStatus(string $jobID, BackupJobStatus $backupJobStatus = null)
    {
        self::throwNotImplemented();
    }

    public function getHost()
    {
        return $this->hostInfo;
    }

    public function getBasicHost()
    {
        self::throwNotImplemented();
    }

    public function getAgentLogs(int $severity = self::DEFAULT_LOG_SEVERITY, ?int $limit = self::DEFAULT_LOG_LINES)
    {
        self::throwNotImplemented();
    }

    public function getLatestWorkingCert(): CertificateSet
    {
        self::throwNotImplemented();
    }

    public function runCommand(string $command, array $commandArguments = [], string $directory = null)
    {
        self::throwNotImplemented();
    }

    public function needsReboot(): bool
    {
        return false;
    }

    public function wantsReboot(): bool
    {
        return false;
    }

    public function getAgentRequest(): AgentRequest
    {
        self::throwNotImplemented();
    }

    public function needsOsUpdate(): bool
    {
        return false;
    }

    public function isAgentVersionSupported(): bool
    {
        return true;
    }

    private static function throwNotImplemented()
    {
        throw new \Exception("Not implemented");
    }
}
