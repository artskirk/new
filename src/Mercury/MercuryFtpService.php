<?php

namespace Datto\Mercury;

use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceState;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\DateTimeService;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Systemd\Systemctl;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Utility class for interfacing with the Mercury FTP service on the device.
 *
 * @author Justin Giacobbi <justin@datto.com>
 * @author Christopher Bitler <cbitler@datto.com>
 */
class MercuryFtpService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The control port is opened by mercuryftpd on localhost only and is used to receive commands from mercuryftpctl.
     * It is always +1 from the port configured in /etc/mercuryftp/mercuryftp.conf
     */
    const MERCURYFTP_TRANSFER_PORT = 3262;
    const MERCURYFTP_CTL_PORT = 3263;
    const MERCURYFTP_SERVICE = 'mercuryftp.service';
    const MERCURYFTP_CTL = 'mercuryftpctl';
    const CONFIG_PATH = '/etc/mercuryftp/mercuryftp.conf';
    const CONFIG_PATH_CLOUD = '/etc/mercuryftp/mercuryftp.conf.cloud-device';
    const CONFIG_PATH_ON_PREM = '/etc/mercuryftp/mercuryftp.conf.on-prem-device';

    const LIST_COMMAND = 'list';
    const ADD_COMMAND = 'add';
    const DELETE_COMMAND = 'del';

    private ProcessFactory $processFactory;
    private FirewallService $firewallService;
    private Systemctl $systemctl;
    private Filesystem $filesystem;
    private FeatureService $featureService;
    private DeviceState $deviceState;
    private DateTimeService $dateTimeService;

    public function __construct(
        ProcessFactory $processFactory,
        FirewallService $firewallService,
        Systemctl $systemctl,
        Filesystem $filesystem,
        FeatureService $featureService,
        DeviceState $deviceState,
        DateTimeService $dateTimeService
    ) {
        $this->processFactory = $processFactory;
        $this->firewallService = $firewallService;
        $this->systemctl = $systemctl;
        $this->filesystem = $filesystem;
        $this->featureService = $featureService;
        $this->deviceState = $deviceState;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Checks whether the mercury ftp service is running.
     *
     * @return bool true if the service is running, false if it is not.
     */
    public function isAlive(): bool
    {
        return $this->systemctl->isActive(self::MERCURYFTP_SERVICE);
    }

    /**
     * Starts the mercury ftp service.
     *
     * @return bool true if the service is successfully started, false if it is not.
     */
    public function start(): bool
    {
        try {
            $this->systemctl->start(self::MERCURYFTP_SERVICE);
            $this->logger->debug('MFV0001 Successfully started the mercury ftp service.');
            return true;
        } catch (Throwable $t) {
            $this->logger->error('MFV0002 Failed to start the mercury ftp service.', ['errorMessage' => $t->getMessage()]);
        }

        return false;
    }

    /**
     * Restarts the mercury ftp service.
     *
     * @return bool true if the service is successfully restarted, false if it is not.
     */
    public function restart(): bool
    {
        try {
            $this->systemctl->restart(self::MERCURYFTP_SERVICE);
            $this->logger->debug('MFV0005 Successfully started the mercury ftp service.');
            $this->deviceState->clear(DeviceState::MERCURYFTP_RESTART_REQUESTED);
            return true;
        } catch (Throwable $t) {
            $this->logger->error('MFV0006 Failed to restart the mercury ftp service.', ['exception' => $t]);
        }

        return false;
    }

    /**
     * List targets and LUNs
     *
     * @return array Array of targets and the LUNs in each target
     */
    public function listTargets(): array
    {
        $targetList = $this->controlCommand([self::LIST_COMMAND]);

        $result = json_decode(trim($targetList), true);
        return $result ?: [];
    }

    /**
     * Add a target
     *
     * @param string $targetName Name of the target
     * @param string|null $password Password for the target, or null for none
     */
    public function addTarget(string $targetName, string $password = null): void
    {
        $args = [self::ADD_COMMAND, $targetName];
        if ($password !== null) {
            $args[] = $password;
        }

        $this->controlCommand($args);
        $this->firewallService->enableMercuryFtp(true);
    }

    /**
     * Delete a target
     *
     * @param string $targetName The name of the target
     */
    public function deleteTarget(string $targetName): void
    {
        $this->controlCommand([self::DELETE_COMMAND, $targetName]);
        if (count($this->listTargets()) === 0) {
            $this->firewallService->enableMercuryFtp(false);
        }
    }

    /**
     * Add a LUN to a target
     *
     * @param string $targetName Name of the target to add the LUN to
     * @param int $index The index of the LUN
     * @param string $lunPath The path to the file for the LUN
     */
    public function addLun(string $targetName, int $index, string $lunPath): void
    {
        $this->controlCommand([
            self::ADD_COMMAND,
            $targetName,
            $index,
            $lunPath
        ]);
    }

    /**
     * Delete a LUN from a target
     *
     * @param string $targetName The target to delete the LUN from
     * @param int $index The index of the target
     */
    public function deleteLun(string $targetName, int $index): void
    {
        $this->controlCommand([
            self::DELETE_COMMAND,
            $targetName,
            $index
        ]);
    }

    /**
     * Check if mercuryftp is ready.
     */
    public function isReady(): bool
    {
        try {
            return is_array($this->listTargets());
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Sets the MercuryFTP configuration file to the proper one.
     */
    public function configure(): void
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_MERCURY_SHARES_APACHE_CERT)) {
            $targetConfig = self::CONFIG_PATH_ON_PREM;
        } else {
            $targetConfig = self::CONFIG_PATH_CLOUD;
        }
        $this->filesystem->unlinkIfExists(self::CONFIG_PATH);
        if (!$this->filesystem->symlink($targetConfig, self::CONFIG_PATH)) {
            $this->logger->warning('MFV0003 Failed to symlink mercuryftp config file.');
        }
    }

    /**
     * Asks the MercuryFTP service to restart itself if it is using the certificate. This function doesn't itself
     * restart the service; it just writes a file indicating that it needs to be restarted.
     *
     * @param string $certPath The path to the certificate file.
     */
    public function requestToRestart(string $certPath): void
    {
        try {
            // If the certificate is not in the config file, we don't need to restart.
            $configContents = $this->filesystem->fileGetContents(self::CONFIG_PATH);
            if (!$configContents) {
                throw new Exception('Failed to read config file.');
            }
            if (preg_match('/^tls_certificate_file\s*=\s*' . $certPath . '$', $configContents) === 0) {
                return;
            }
            $timestamp = $this->dateTimeService->getTime();
            if (!$this->deviceState->set(DeviceState::MERCURYFTP_RESTART_REQUESTED, $timestamp)) {
                throw new Exception('Failed to write restart file.');
            }
            $this->logger->info('MFV0003 MercuryFTP restart requested.');
        } catch (Throwable $ex) {
            $this->logger->error('MFV0004 Failed to request MercuryFTP service to restart.', ['exception' => $ex]);
        }
    }

    /**
     * Execute a mercuryftpctl command
     *
     * @param array $args command arguments from left to right
     * @return string The output of the command
     */
    private function controlCommand(array $args): string
    {
        array_unshift($args, self::MERCURYFTP_CTL);
        $args[] = '-p';
        $args[] = self::MERCURYFTP_CTL_PORT;

        $controlProcess = $this->processFactory->get($args);
        $controlProcess->enableOutput();

        $controlProcess->mustRun();

        return $controlProcess->getOutput();
    }
}
