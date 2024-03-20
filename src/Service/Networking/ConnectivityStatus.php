<?php

namespace Datto\Service\Networking;

use Datto\Resource\DateTimeService;
use Datto\Utility\Network\Ping;

/**
 * Contains the status of network connectivity with Datto endpoints required by the device.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class ConnectivityStatus
{
    private int $ping1472LossPercentage;
    private int $ping1500LossPercentage;
    private bool $dns;
    private bool $isTargetServerAvailable;
    private bool $port22;
    private bool $port80;
    private bool $port443;
    private bool $device443;
    private bool $bmc443;
    private bool $speedtest21;
    private bool $heartbeat80;
    private bool $upgradeServer443;
    private bool $ntp123;
    private DateTimeService $dateTimeService;

    public function __construct(
        int $ping1472LossPercentage,
        int $ping1500LossPercentage,
        bool $dns,
        bool $isTargetServerAvailable,
        bool $port22,
        bool $port80,
        bool $port443,
        bool $device443,
        bool $bmc443,
        bool $speedtest21,
        bool $heartbeat80,
        bool $upgradeServer443,
        bool $ntp123,
        DateTimeService $dateTimeService
    ) {
        $this->ping1472LossPercentage = $ping1472LossPercentage;
        $this->ping1500LossPercentage = $ping1500LossPercentage;
        $this->dns = $dns;
        $this->isTargetServerAvailable = $isTargetServerAvailable;
        $this->port22 = $port22;
        $this->port80 = $port80;
        $this->port443 = $port443;
        $this->device443 = $device443;
        $this->bmc443 = $bmc443;
        $this->speedtest21 = $speedtest21;
        $this->heartbeat80 = $heartbeat80;
        $this->upgradeServer443 = $upgradeServer443;
        $this->ntp123 = $ntp123;
        $this->dateTimeService = $dateTimeService;
    }

    public function isTargetServerAvailable(): bool
    {
        return $this->isTargetServerAvailable;
    }

    public function isPort22Available(): bool
    {
        return $this->port22;
    }

    public function isPort80Available(): bool
    {
        return $this->port80;
    }

    public function isPort443Available(): bool
    {
        return $this->port443;
    }

    public function isDevice443Available(): bool
    {
        return $this->device443;
    }

    public function isBmc443Available(): bool
    {
        return $this->bmc443;
    }

    public function isSpeedtest21Available(): bool
    {
        return $this->speedtest21;
    }

    public function isHeartbeat80Available(): bool
    {
        return $this->heartbeat80;
    }

    public function isUpgradeServerAvailable(): bool
    {
        return $this->upgradeServer443;
    }

    public function setUpgradeServerAvailable(bool $val): void
    {
        $this->upgradeServer443 = $val;
    }

    public function getPing1472LossPercentage(): int
    {
        return $this->ping1472LossPercentage;
    }

    public function getPing1500LossPercentage(): int
    {
        return $this->ping1500LossPercentage;
    }

    public function isDnsAvailable(): bool
    {
        return $this->dns;
    }

    public function isNtp123Available(): bool
    {
        return $this->ntp123;
    }

    public function asArray(): array
    {
        return [
            'created' => $this->dateTimeService->getTime(),
            'ping-1472' => $this->getPing1472LossPercentage() < Ping::PING_THRESHOLD_PERCENTAGE,
            'ping-1500' => $this->getPing1500LossPercentage() < Ping::PING_THRESHOLD_PERCENTAGE,
            'dns' => $this->isDnsAvailable(),
            'server' => $this->isTargetServerAvailable(),
            'port-22' => $this->isPort22Available(),
            'port-80' => $this->isPort80Available(),
            'port-443' => $this->isPort443Available(),
            'device-443' => $this->isDevice443Available(),
            'bmc-443' => $this->isBmc443Available(),
            'speedtest-21' => $this->isSpeedtest21Available(),
            'heartbeat-80' => $this->isHeartbeat80Available(),
            'upgrade-server-443' => $this->isUpgradeServerAvailable(),
            'ntp-123' => $this->isNtp123Available()
        ];
    }
}
