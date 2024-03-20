<?php

namespace Datto\Connection\Service;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Log\SanitizedException;
use Datto\Winexe\Exception\InvalidLoginException;
use Datto\Winexe\WinexeApi;
use Datto\Winexe\WinexeApiFactory;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service class for managing Hyper-V connection objects.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class HvConnectionService implements ConnectionServiceInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var int The port for HTTP the WinRM listener on the Hyper-V host is listening on. */
    private const WINRM_HTTP_PORT = 5985;

    /** @var int The port for HTTPS the WinRM listener on the Hyper-V host is listening on. */
    private const WINRM_HTTPS_PORT = 5986;

    private ConnectionService $connectionService;
    private WinexeApiFactory $winexeApiFactory;

    public function __construct(
        ConnectionService $connectionService,
        WinexeApiFactory $winexeApiFactory
    ) {
        $this->connectionService = $connectionService;
        $this->winexeApiFactory = $winexeApiFactory;
    }

    /**
     * Create a new hypervisor connection and perform initial host configuration.
     */
    public function createAndConfigure(string $name, array $params): HvConnection
    {
        $connection = $this->create($name);
        $this->setConnectionParams($connection, $params);

        if (!$connection->isValid()) {
            throw new InvalidArgumentException('Invalid parameters passed.');
        }

        $this->setupHypervRemoteAccess($connection);
        $this->setHypervisorVersion($connection);

        // Remote access must be configured above before attempting a libvirt connection
        $this->verifyCredentials($connection);

        return $connection;
    }

    public function connect(array $params): bool
    {
        $connection = new HvConnection('test-connection');

        $this->setConnectionParams($connection, $params);

        $this->setupHypervRemoteAccess($connection);

        return $this->verifyCredentials($connection);
    }

    public function create(string $name): HvConnection
    {
        return $this->connectionService->create($name, ConnectionType::LIBVIRT_HV());
    }

    /**
     * {@inheritdoc}
     */
    public function delete(AbstractLibvirtConnection $connection): bool
    {
        $name = $connection->getName();

        if (!$connection instanceof HvConnection) {
            throw new Exception("Cannot delete connection \"$name\", not an instance of HvConnection.");
        }

        return $this->connectionService->delete($connection);
    }

    /**
     * @return bool true if exists
     */
    public function exists(string $name): bool
    {
        try {
            $existing = $this->get($name);
        } catch (Exception $e) {
            $existing = null;
        }

        return $existing !== null;
    }

    /**
     * @return HvConnection|null
     */
    public function get(string $name): ?AbstractLibvirtConnection
    {
        $connectionFile = $this->connectionService->getExistingConnectionFile($name, ConnectionType::LIBVIRT_HV());

        if ($connectionFile === null) {
            return null;
        }

        /** @var HvConnection $connection */
        $connection = $this->connectionService->getFromFile($connectionFile);

        if (!$connection->isValid()) {
            throw new Exception("Specified connection \"$name\" is invalid.");
        }

        return $connection;
    }

    /**
     * @return HvConnection[]
     */
    public function getAll(): array
    {
        $connections = array();
        $connectionFiles = $this->connectionService->getAllConnectionFiles(ConnectionType::LIBVIRT_HV());

        foreach ($connectionFiles as $connectionFile) {
            /** @var HvConnection $connection */
            $connection = $this->connectionService->getFromFile($connectionFile);

            if ($connection->isValid()) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @inheritdoc
     */
    public function refreshAll(): void
    {
        foreach ($this->getAll() as $connection) {
            try {
                $this->save($connection);
            } catch (Throwable $e) {
                $this->logger->error('HVC1004 Error refreshing connection', [
                    'connection' => $connection->getName(),
                    'exception' => $e
                ]);
            }
        }
    }

    public function getHypervisorOptions(int $hypervisorOption, array $params): array
    {
        // TODO: Implement getHypervisorOptions() method.
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function save(AbstractLibvirtConnection $connection): bool
    {
        $name = $connection->getName();

        if (!$connection instanceof HvConnection) {
            throw new Exception("Cannot save connection \"$name\", not an instance of HvConnection.");
        }

        if (!$connection->isValid()) {
            throw new Exception("Cannot save connection \"$name\", invalid parameters passed.");
        }

        $isGood = $this->verifyCredentials($connection);

        if ($isGood === true) {
            $this->setHypervisorVersion($connection);
            $this->connectionService->setAsPrimaryIfFirst($connection);
            $isGood = $connection->saveData();
        }

        return $isGood;
    }

    public function setConnectionParams(AbstractLibvirtConnection $connection, array $params): HvConnection
    {
        if (!$connection instanceof HvConnection) {
            throw new Exception('Invalid connection type. Must be an HvConnection');
        }

        $connection->setHostname($params['server']);
        $connection->setUser($params['username']);
        $connection->setPassword($params['password']);
        $connection->setDomain($params['domain']);

        if (isset($params['http']) && is_bool($params['http'])) {
            $useHttp = $params['http'];
        } else {
            $useHttp = true;
        }
        $connection->setHttp($useHttp);

        if (isset($params['port']) && is_int($params['port'])) {
            $connection->setPort($params['port']);
        } else {
            $connection->setPort($useHttp ? self::WINRM_HTTP_PORT : self::WINRM_HTTPS_PORT);
        }

        return $connection;
    }

    /**
     * Creates a new connection with the same name, connection parameters, and IsPrimary property
     *
     * @return bool true if successful
     */
    public function copy(array $connectionToCopy): bool
    {
        $connection = $this->create($connectionToCopy['name']);
        $this->setConnectionParams($connection, $connectionToCopy['connectionParams']);
        if (array_key_exists('isPrimary', $connectionToCopy['connectionParams'])) {
            $connection->setIsPrimary($connectionToCopy['connectionParams']['isPrimary']);
        }
        return $this->save($connection);
    }

    /**
     * Returns true if the error indicates that the Hyper-V server has an invalid certificate.
     */
    public static function isInvalidCertificateError(Throwable $throwable): bool
    {
        // This is the error that libvirt returns when the certificate is not trusted or the certificate is expired.
        return str_contains($throwable->getMessage(), "Peer's certificate wasn't OK");
    }

    /**
     * Test connection to hypervisor using libvirt
     *
     * @param HvConnection $connection
     *
     * @return bool true if connection was established
     */
    private function verifyCredentials(HvConnection $connection): bool
    {
        $this->logger->debug('HVC1005 Verifying credentials', [
            'connection' => $connection->getName(),
            'user' => $connection->getUser(),
            'domain' => $connection->getDomain(),
            'uri' => $connection->getUri(),
        ]);

        $libvirt = $connection->getLibvirt();
        if (!$libvirt->isConnected()) {
            throw new Exception($libvirt->getLastError());
        }

        return true;
    }

    /**
     * Prepares Hyper-V host for remote virtualization.
     *
     * Ensures WinRM service is setup to handle WS-MAN API requests and that
     * there's iSCSI service running that can be used to discover our LUNs.
     */
    private function setupHypervRemoteAccess(HvConnection $connection): void
    {
        $winexeApi = $this->getWinExeApi($connection);

        try {
            $this->logger->info('HVC1000 Setting up WinRM service.');
            $this->setupWinRmService($winexeApi);
            $this->logger->info('HVC1001 WinRM setup successful.');
        } catch (InvalidLoginException $ex) {
            // let this one through.
            throw $ex;
        } catch (Exception $ex) {
            $ex = new SanitizedException($ex, [$connection->getUser(), $connection->getPassword()]);
            $this->logger->critical('HVH1100 Failed to setup WinRM service', ['exception' => $ex]);
            throw new Exception('Failed to setup WinRM service');
        }

        try {
            $this->logger->info('HVC1002 Setting up MSiSCSI service.');
            $this->setupMSiSCSIService($winexeApi);
            $this->logger->info('HVC1003 MSiSCSI setup successful.');
        } catch (Exception $ex) {
            $ex = new SanitizedException($ex, [$connection->getUser(), $connection->getPassword()]);
            $this->logger->critical('HVC1101 Failed to setup MSiSCSI service', ['exception' => $ex]);
            throw new Exception('Failed to setup MSiSCSI service');
        }
    }

    /**
     * Queries the windows for version and sets it on the connection.
     */
    private function setHypervisorVersion(HvConnection $connection): void
    {
        $version = '';

        //  the version number provided by libvirt is truncated and incorrect, so query windows directly
        try {
            $winexeApi = $this->getWinExeApi($connection);
            // ex: 10.0.14393.0
            $version = trim($winexeApi->runPowerShellCommand('[System.Environment]::OSVersion.Version.ToString()'));
        } catch (Throwable $e) {
            throw new Exception(sprintf(
                'Could not get Hyper-V version: %s',
                $e->getMessage()
            ));
        }

        $connection->setHypervisorVersion($version);
    }

    /**
     * Makes sure WinRM service is configured and accepts http connections.
     */
    private function setupWinRmService(WinexeApi $winexeApi): void
    {
        $configure = 'winrm quickconfig -q';

        try {
            /* since this is the fist command to actually run, set timeout to 15s
             * as the RPC port is closed by default so it will likely timeout
             * unless customer enables firewall rule
             */
            $winexeApi->runCliCommand($configure, 15);
        } catch (Exception $ex) {
            // this errors out even if it's already setup, so handle accordingly
            $message = $ex->getMessage();

            // yep, those messages differ in Win2k8 and 2k12 in this way...
            $tokens = array(
                'WinRM is already set up',
                'WinRM already is set up',
            );

            $isGood = false;
            foreach ($tokens as $token) {
                $isGood = strpos($message, $token) !== false;

                if ($isGood) {
                    break;
                }
            }

            if ($isGood === false) {
                throw $ex;
            }
        }

        // This is needed at least for 1920x1080 screenshots at 16 bit depth, defaults to 500
        $bumpEnvelopeSize = 'winrm set winrm/config @{MaxEnvelopeSizekb="20000"}';
        $winexeApi->runCliCommand($bumpEnvelopeSize);

        $setWinRmService = 'winrm set winrm/config/service';

        $setBasicAuth = sprintf('%s/auth @{Basic="true"}', $setWinRmService);
        $winexeApi->runCliCommand($setBasicAuth);

        $setUnencrypted = sprintf('%s @{AllowUnencrypted="true"}', $setWinRmService);
        $winexeApi->runCliCommand($setUnencrypted);
    }

    /**
     * Enables and start MSiSCSI service.
     */
    private function setupMSiSCSIService(WinexeApi $winexeApi): void
    {
        $enableService = 'Set-Service -Name MSiSCSI -StartupType Automatic';
        $winexeApi->runPowerShellCommand($enableService);

        $startService = 'Start-Service MSiSCSI';
        $winexeApi->runPowerShellCommand($startService);
    }

    /**
     * Create WinexeApi instance from a connection
     */
    private function getWinExeApi(HvConnection $connection): WinexeApi
    {
        return $this->winexeApiFactory->create(
            $connection->getHostname(),
            $connection->getUser(),
            $connection->getPassword(),
            $connection->getDomain()
        );
    }
}
