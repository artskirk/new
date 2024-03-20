<?php

namespace Datto\System\Ssh;

use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\System\Api\DeviceApiClientService;
use Datto\Service\Registration\SshKeyService;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\Migration\Device\DeviceMigrationExceptionCodes;
use Exception;
use Throwable;

/**
 * SSH client service used for device migrations.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class SshClient
{
    const REMOTE_CALL_TIMEOUT = 10;

    /** @var DeviceApiClientService */
    private $deviceClientService;

    private ProcessFactory $processFactory;

    /** @var SshKeyService */
    private $sshKeyService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var JsonRpcClient */
    private $deviceWebClient;

    public function __construct(
        DeviceApiClientService $deviceClientService,
        ProcessFactory $processFactory,
        SshKeyService $sshKeyService,
        DeviceLoggerInterface $logger,
        JsonRpcClient $deviceWebClient
    ) {
        $this->deviceClientService = $deviceClientService;
        $this->processFactory = $processFactory;
        $this->sshKeyService = $sshKeyService;
        $this->logger = $logger;
        $this->deviceWebClient = $deviceWebClient;
    }

    /**
     * Get the hostname.
     *
     * @return string
     */
    public function getHostname(): string
    {
        $deviceClient = $this->deviceClientService->getDeviceClient();
        $hostname = $deviceClient->getSshIp();

        if (!$hostname) {
            $hostname = $deviceClient->getHostname();
        }

        return $hostname;
    }

    /**
     * Get the port that the SSH server is running on.
     *
     * @return int
     */
    public function getPort(): int
    {
        return SshServer::SSH_PORT;
    }

    /**
     * Get the authorized user.
     *
     * @return string
     */
    public function getAuthorizedUser(): string
    {
        return SshServer::SSH_AUTHORIZED_USER;
    }

    /**
     * Sends the current system's public key to a remote device after obtaining an authorization token from deviceweb.
     */
    public function startRemoteSshServer()
    {
        $this->sshKeyService->generateSshKeyIfNotExists();
        $publicKey = $this->sshKeyService->getSshPublicKey();
        if ($publicKey === false) {
            $message = "Can't read public key file";
            $this->logger->error("DSS0110 $message");
            throw new Exception($message);
        }
        $sourceDeviceID = $this->deviceClientService->call(
            'v1/device/settings/getDeviceId',
            [],
            self::REMOTE_CALL_TIMEOUT
        );
        try {
            $authToken = $this->deviceWebClient->queryWithId(
                'v1/device/migration/getMigrationAuthorization',
                ['sourceDeviceID' => $sourceDeviceID]
            );
        } catch (Throwable $e) {
            $this->logger->error("MIG0050 Device-web authorization failed", ['message' => $e->getMessage()]);
            throw new Exception(
                'Device-web authorization failed',
                DeviceMigrationExceptionCodes::NOT_AUTHORIZED_DEVICEWEB
            );
        }
        $this->deviceClientService->call(
            'v1/device/migrate/ssh/startServer',
            [ 'publicKey' => $publicKey, 'authToken' => $authToken ],
            self::REMOTE_CALL_TIMEOUT
        );
    }

    /**
     * Copy a file from the remote device to the local device.
     * This preserves dates, times, and permissions.
     *
     * @param string $remoteSourcePath Absolute path to file on source device.
     * @param string $localDestinationPath Absolute path to file or directory.
     * @param bool $mustRun default to true, controls exception throws
     */
    public function copyFromRemote(string $remoteSourcePath, string $localDestinationPath, bool $mustRun = true)
    {
        $hostname = $this->getHostname();
        $port = $this->getPort();
        $user = $this->getAuthorizedUser();

        $process = $this->processFactory
            ->get([
                'scp',
                '-p',
                '-o', 'ConnectTimeout=15',
                '-o', 'PasswordAuthentication=no',
                '-o', 'StrictHostKeyChecking=no',
                '-P', $port,
                "$user@$hostname:$remoteSourcePath",
                $localDestinationPath
            ]);
        if ($mustRun) {
            $process->mustRun();
        } else {
            $process->run();
        }
    }

    public function stopRemoteSshServer()
    {
        $this->deviceClientService->call('v1/device/migrate/ssh/stopServer', [], self::REMOTE_CALL_TIMEOUT);
    }
}
