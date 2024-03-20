<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Certificate\CertificateSet;
use Datto\Asset\Agent\Job\BackupJobStatus;

/**
 * Common interface for all backup agent APIs
 * todo: revisit the mixed responses when the clients are refactored
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
interface AgentApi
{
    const RETRIES = 3;
    const RETRY_WAIT_TIME_SECONDS = 2;

    const DEFAULT_LOG_LINES = null;
    const DEFAULT_LOG_SEVERITY = 3;

    /**
     * Returns the platform that this api communicates with
     */
    public function getPlatform(): AgentPlatform;

    /**
     * Initialize the API.
     */
    public function initialize();

    /**
     * Cleans up any potential state created during api initialization.
     */
    public function cleanup();

    /**
     * Send a request to the agent to pair.
     *
     * @param string|null $agentKeyName
     * @return mixed
     */
    public function pairAgent(string $agentKeyName = null);

    /**
     * Send the agent a pairing ticket issued by device-web.
     * Pairing tickets are used to securely notify the agent that this device is authorized to pair with the agent.
     * They originate from device web and are sent, securely, to the agent via the device.
     *
     * @param array $ticket
     * @return mixed
     */
    public function sendAgentPairTicket(array $ticket);

    /**
     * Send a request to the agent to start a backup.
     *
     * @param BackupApiContext $backupContext
     * @return mixed
     */
    public function startBackup(BackupApiContext $backupContext);

    /**
     * Send a request to the agent to cancel a backup.
     *
     * @param string $jobID
     * @return mixed
     */
    public function cancelBackup(string $jobID);

    /**
     * Send a request to the agent to get the status of a backup.
     *
     * @param string $jobID If not empty, will return a BackupJobStatus object
     * @param BackupJobStatus|null $backupJobStatus If $backupJobStatus is null, a new BackupJobStatus will be created
     * @return BackupJobStatus|null|array If an empty jobID is provided, this will return an array in the format:
     * {
     *   "<jobTransferState>": [
     *     "<jobID>",
     *     ...
     *   ],
     *   ...
     * }
     */
    public function updateBackupStatus(string $jobID, BackupJobStatus $backupJobStatus = null);

    /**
     * Send a request to the agent to get the host information.
     *
     * @return mixed
     */
    public function getHost();

    /**
     * Send a request to the agent to get the basichost information.
     * @return mixed
     */
    public function getBasicHost();

    /**
     * Send a request to the agent to get the agent logs.
     *
     * @param int $severity
     * @param int|null $limit Number of logs to be returned
     *  If limit is not null, the number of logs will be trimmed to the given limit from the end of the array.
     *  Example (with limit of 2):
     *      $response = ['log' => ['log1', 'log2', 'log3']]
     *      $response (after splice) = ['log' => ['log2', 'log3']]
     *
     * @return mixed
     */
    public function getAgentLogs(int $severity = self::DEFAULT_LOG_SEVERITY, ?int $limit = self::DEFAULT_LOG_LINES);

    /**
     * Gets the hash that we can use to identify the certificate that allowed ssl communication with the agent
     * on the most recent requests
     * @return CertificateSet Returns the CertificateSet that was used to communicate, otherwise
     *      throws an exception if a non-SSL transport error occurred when testing the connection
     *          (i.e. can't determine whether or not the available certificate sets will work), or
     *          (i.e. None of the available certificate sets are good)
     */
    public function getLatestWorkingCert(): CertificateSet;

    /**
     * Run an arbitrary command on the agent.
     *
     * @param string $command
     * @param array $commandArguments
     * @param string $directory
     */
    public function runCommand(
        string $command,
        array $commandArguments = [],
        string $directory = null
    );

    /**
     * Check whether the agent machine needs to reboot
     *
     * @return bool True if it needs to reboot, false if it doesn't need to
     */
    public function needsReboot(): bool;

    /**
     * Check whether the agent desires a reboot, EG to apply updates
     *
     * @return bool True if the agent wants to reboot, false if it doesn't
     */
    public function wantsReboot(): bool;

    /**
     * Returns the agent request object to make manual endpoint calls
     *
     * @return AgentRequest
     */
    public function getAgentRequest(): AgentRequest;

    /**
     * Check whether the agent machine has OS updates to be applied
     * on next reboot.  Currently only available for Datto Windows agents.
     *
     * @return bool True if agent has updates pending, false if not
     */
    public function needsOsUpdate(): bool;

    /**
     * Determine if the agent version is supported
     */
    public function isAgentVersionSupported(): bool;
}
