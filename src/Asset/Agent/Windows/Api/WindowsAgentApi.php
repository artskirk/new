<?php

namespace Datto\Asset\Agent\Windows\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\BackupApiContext;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Throwable;

/**
 * Interfaces with the Windows Agent API
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class WindowsAgentApi extends DattoAgentApi
{
    const RUN_DEFAULT_DIRECTORY = 'C:\\';

    /** Default windows agent port */
    const AGENT_PORT = 25568;

    /**
     * Minimum version of DWA that supports secure pairing
     */
    const SECURE_PAIRING_AGENT_VERSION = '1.1.0.0';

    public function getPlatform(): AgentPlatform
    {
        return AgentPlatform::DATTO_WINDOWS_AGENT();
    }

    /**
     * @inheritdoc
     */
    public function runCommand(
        string $command,
        array $commandArguments = [],
        string $directory = null
    ) {
        $directory = $directory ?? self::RUN_DEFAULT_DIRECTORY;
        $response = false;
        if ($command) {
            $this->logger->debug("CMD0005 Running command against Windows agent: \"$command \"" . implode(' ', $commandArguments) . "\" in directory:\"$directory\"");
            $response = $this->agentRequest->post('command', json_encode([
                "executable" => $command,
                "working_dir" => $directory,
                "parameters" => $commandArguments,
            ]), true);
        }
        return $response;
    }

    /**
     * @inheritdoc
     */
    protected function getBackupRequestParams(BackupApiContext $backupContext): array
    {
        $requestParams = [
            "waitBetweenVols" => false,
            "forceDiffMerge" => $backupContext->isForceDiffMerge(),
            "forceCopyFull" => $backupContext->isForceCopyFull(),
            "writeSize" => 0,
            "snapshotMethod" => $backupContext->getBackupEngineUsed(),
            "volumes" => $this->getVolumeArray($backupContext),
            "VSSExclusions" => $backupContext->getVssExclusions()
        ];

        return $requestParams;
    }

    /**
     * @inheritDoc
     */
    public function needsOsUpdate(): bool
    {
        try {
            $response = $this->agentRequest->get('v2/system/updates');
            if (isset($response['rebootRequired']) && $response['rebootRequired'] === true) {
                return true;
            }
        } catch (Throwable $e) {
            // New endpoint--let exceptions fail out gracefully.
        }

        return false;
    }

    /**
     * Determine if the DWA version is supported
     *
     * We must first pair the agent to the device, via the /pair endpoint. The response may be one of three values:
     *   200: newer API (1.10 and higher), successfully paired
     *   201: older API (1.0.6 and lower), successfully paired
     *   array with key 'getPairTicket': newer API, the agent is registered with another device
     *
     * If one of the first two values are returned, we call out to /host to get the agent version,
     * then compare it with the minimum secured-pairing agent version.
     *
     * If the third value is returned, secure pairing is supported.
     *
     * Note: If the agent is version 1.0.6 or lower, the pairing will cause the agent to stop communicating
     * with the device it was previously communicating with, nothing else beyond that is affected. The agent
     * can be re-paired with the previous device and will begin to backup to that device again.
     */
    public function isAgentVersionSupported(): bool
    {
        try {
            $data = json_encode(['deviceID' => (int) $this->deviceConfig->getDeviceId()]);
            $pairResults = $this->agentRequest->post('pair', $data);

            if ($pairResults === 200 || $pairResults === 201) {
                // 200 / 201 = Agent successfully paired to the device, now to get apiVersion
                $host = $this->getHost();
                $apiVersion = $host['agentVersion'] ?? '0'; // Assume 0 if no agentVersion found
                return version_compare($apiVersion, self::SECURE_PAIRING_AGENT_VERSION) >= 0;
            }

            if (isset($pairResults['getPairTicket'])) {
                // Secure pairing, the agent is paired with another device
                return true;
            }
            // Unable to determine API version, or it is not supported
            return false;
        } catch (\Throwable $e) {
            // A CURL error occurred, the message was previously logged
            return false;
        }
    }
}
