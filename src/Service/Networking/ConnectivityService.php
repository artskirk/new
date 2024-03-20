<?php

namespace Datto\Service\Networking;

use Datto\Cloud\SpeedSync;
use Datto\Config\DeviceState;
use Datto\Config\ServerNameConfig;
use Datto\Resource\DateTimeService;
use Datto\Util\NetworkSystem;
use Datto\Utility\Network\DnsLookup;
use Datto\Utility\Network\Nmap;
use Datto\Utility\Network\Ping;

/**
 * Tests network connectivity with Datto endpoints required by the device.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class ConnectivityService
{
    const OK_STRING = "  OK" . PHP_EOL;
    const FAILURE_STRING = "  FAILURE" . PHP_EOL;
    const ONE_DAY_IN_SECONDS = 86400;
    const ONE_WEEK_IN_SECONDS = 604800;
    const PING_SIZE_WHICH_FRAGMENTS = 1500;
    const PING_SIZE_WHICH_DOES_NOT_FRAGMENT = 1472;

    private ServerNameConfig $serverNameConfig;
    private SpeedSync $speedSync;
    private NMap $nmap;
    private DnsLookup $dnsLookup;
    private DeviceState $deviceState;
    private Ping $ping;
    private NetworkSystem $networkSystem;
    private DateTimeService $dateTimeService;

    public function __construct(
        ServerNameConfig $serverNameConfig,
        NetworkSystem $networkSystem,
        SpeedSync $speedSync,
        NMap $nmap,
        DnsLookup $dnsLookup,
        DeviceState $deviceState,
        Ping $ping,
        DateTimeService $dateTimeService
    ) {
        $this->serverNameConfig = $serverNameConfig;
        $this->networkSystem = $networkSystem;
        $this->speedSync = $speedSync;
        $this->nmap = $nmap;
        $this->dnsLookup = $dnsLookup;
        $this->deviceState = $deviceState;
        $this->ping = $ping;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Generates connectivity array and saves it to CONNECTIVITY_STATE file.
     * @param callable|null $onStatusChanged This is a callback used by ConnectivityTestCommand to display the
     *        current activity to user for `snapctl network:connectivity:test` command.
     * @param bool $useCache If 'useCache' is true, it will return the contents of CONNECTIVITY_STATE file,
     *        if the file was generated less than around a week ago. If it is older, it will regenerate it.
     * @return array|mixed
     */
    public function getConnectivityState(callable $onStatusChanged = null, bool $useCache = false): array
    {
        $now = $this->dateTimeService->getTime();
        if (!$onStatusChanged) {
            $onStatusChanged = function (string $status): void {
            };
        }
        $deviceState = json_decode($this->deviceState->getRaw(DeviceState::CONNECTIVITY_STATE, null), true);
        $isValidJson = $deviceState !== null && isset($deviceState['nextCheckTime']) && isset($deviceState['created']);
        $nextCheckTime = $isValidJson ? $deviceState['nextCheckTime'] : $now;
        if ($isValidJson && $useCache && $now < $nextCheckTime) {
            $onStatusChanged("It is not time for the next network connectivity check. Done. ");
            return $deviceState;
        }

        $conStatus = $this->getStatus($onStatusChanged)->asArray();
        $conStatus['created'] = $now;
        $randomOffset = rand(0, self::ONE_DAY_IN_SECONDS);
        $nextCheckTime = $now + self::ONE_WEEK_IN_SECONDS + $randomOffset;
        $conStatus['nextCheckTime'] = $nextCheckTime;
        $this->deviceState->setRaw(DeviceState::CONNECTIVITY_STATE, json_encode($conStatus));

        return $conStatus;
    }

    /**
     * Gets the status of specific ports for device connectivity.
     */
    private function getStatus(callable $onStatusChanged): ConnectivityStatus
    {
        // determine if the device has access to a DNS server, this check
        // is used to preclude (through short circuiting) all of the other
        // network checks. Without DNS they will all fail, therefore, we
        // shouldn't attempt to run them
        $onStatusChanged("Checking DNS connectivity...");
        $hasDNS = $this->dnsLookup->lookup($this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM')) !== null;

        // Check ping
        $unfragmentedPing = Ping::FAILED_PING_PERCENTAGE;
        $fragmentedPing = Ping::FAILED_PING_PERCENTAGE;
        if ($hasDNS) {
            $onStatusChanged(self::OK_STRING);

            $onStatusChanged("Pinging datto servers...");
            $unfragmentedPing = $this->ping->pingServer(
                $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM'),
                self::PING_SIZE_WHICH_DOES_NOT_FRAGMENT
            );

            $fragmentedPing = $this->ping->pingServer(
                $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM'),
                self::PING_SIZE_WHICH_FRAGMENTS
            );
            $onStatusChanged(self::OK_STRING);
        } else {
            $onStatusChanged(self::FAILURE_STRING);
        }

        $onStatusChanged("Checking device hostname resolution...");
        // Check ability to resolve device hostname
        $canResolveDeviceHostname = true;
        $hostname = $this->networkSystem->getHostName();
        if ($hasDNS && $this->networkSystem->checkDnsRR($hostname, 'A')) {
            $record = $this->networkSystem->dnsGetRecord($hostname);
            $ip = $this->networkSystem->getHostByName($hostname);
            $canResolveDeviceHostname = $ip === $record[0]['ip'];
            $statusString = $canResolveDeviceHostname ? self::OK_STRING : self::FAILURE_STRING;
            $onStatusChanged($statusString);
        } else {
            $onStatusChanged(self::FAILURE_STRING);
        }

        $onStatusChanged("Checking connectivity to target servers...");
        $isTargetServerAvailable = $this->speedSync->checkTargetServerConnectivity();
        $onStatusChanged($isTargetServerAvailable ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking connectivity with port 22...");
        // Check if ports are open for various services
        $is22open = $hasDNS &&
            $this->nmap->tcpPortScan($this->serverNameConfig->getServer('TEST22_DATTOBACKUP_COM'), 22);
        $onStatusChanged($is22open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking connectivity with port 80...");
        $is80open = $hasDNS &&
            $this->nmap->tcpPortScan($this->serverNameConfig->getServer('TEST80_DATTOBACKUP_COM'), 80);
        $onStatusChanged($is80open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking port 443 on test server...");
        $is443open = $hasDNS &&
            $this->nmap->tcpPortScan($this->serverNameConfig->getServer('TEST443_DATTOBACKUP_COM'), 443);
        $onStatusChanged($is443open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking port 443 on device server...");
        $isDevice443open = $hasDNS &&
            $this->nmap->tcpPortScan($this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM'), 443);
        $onStatusChanged($isDevice443open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking port 443 on BMC server...");
        $isBMC443open = $hasDNS &&
            $this->nmap->tcpPortScan($this->serverNameConfig->getServer('BMC_DATTO_COM'), 443);
        $onStatusChanged($isBMC443open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking port 21 on speed test...");
        $isSpeedtest21open = $hasDNS &&
            $this->nmap->tcpPortScan(
                $this->serverNameConfig->getServer(ServerNameConfig::SPEEDTEST_DATTOBACKUP_COM),
                21
            );
        $onStatusChanged($isSpeedtest21open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking datto heartbeat port 80...");
        $isHeartbeat80open = $hasDNS &&  $this->nmap->tcpPortScan('heartbeat.dattobackup.com', 80);
        $onStatusChanged($isHeartbeat80open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking upgrade server port 443...");
        $isImageServer443Open = $hasDNS &&
            $this->nmap->tcpPortScan('update.datto.com', 443);
        $onStatusChanged($isImageServer443Open ? self::OK_STRING : self::FAILURE_STRING);

        $onStatusChanged("Checking NTP...");
        $isNtpAvailable = $hasDNS && $this->nmap->UdpPortScan('ntp.dattobackup.com', 123);
        $onStatusChanged($isNtpAvailable ? self::OK_STRING : self::FAILURE_STRING);

        return new ConnectivityStatus(
            $unfragmentedPing,
            $fragmentedPing,
            $canResolveDeviceHostname,
            $isTargetServerAvailable,
            $is22open,
            $is80open,
            $is443open,
            $isDevice443open,
            $isBMC443open,
            $isSpeedtest21open,
            $isHeartbeat80open,
            $isImageServer443Open,
            $isNtpAvailable,
            $this->dateTimeService
        );
    }
}
