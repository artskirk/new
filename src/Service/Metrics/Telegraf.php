<?php

namespace Datto\Service\Metrics;

use Datto\Config\DeviceConfig;
use Datto\Utility\Systemd\Systemctl;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Telegraf
{
    private const CONF_DATTO_STATSD_INPUT = '01-datto-statsd-input';
    private const CONF_DATTO_HTTP_OUTPUT = '02-datto-http-output';
    private const CONF_DATTO_CLOUD_DEVICE = '10-datto-cloud-device';
    private const CONF_DATTO_SYSTEM_STATS = '20-datto-system-stats';
    private const CONF_DATTO_ALERTING_STATS = '30-datto-alerting-stats';
    private const CONF_DATTO_DEBUG = '40-datto-debug';
    private const CONF_DATTO_DEBUG_PRINTER = '41-datto-debug-printer';

    private const SERVICE_NAME = 'telegraf';

    private DeviceConfig $deviceConfig;
    private TelegrafConfigToggler $telegrafConfigToggler;
    private Systemctl $systemctl;

    public function __construct(
        DeviceConfig $deviceConfig,
        TelegrafConfigToggler $telegrafConfigToggler,
        Systemctl $systemctl
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->telegrafConfigToggler = $telegrafConfigToggler;
        $this->systemctl = $systemctl;
    }

    /**
     * Write configurations for telegraf.
     *
     * @throws TelegrafConfigException
     */
    public function configure(): void
    {
        $this->enable(self::CONF_DATTO_STATSD_INPUT);
        $this->enable(self::CONF_DATTO_HTTP_OUTPUT);

        if ($this->deviceConfig->isCloudDevice()) {
            $this->enable(self::CONF_DATTO_CLOUD_DEVICE);
            $this->enable(self::CONF_DATTO_SYSTEM_STATS);
            $this->enable(self::CONF_DATTO_ALERTING_STATS);
        } elseif ($this->deviceConfig->isAzureDevice()) {
            $this->enable(self::CONF_DATTO_CLOUD_DEVICE);
            $this->enable(self::CONF_DATTO_SYSTEM_STATS);
        } else {
            // On-Prem specific configurations should go here.
        }
    }

    public function enableDebug(): void
    {
        $this->enable(self::CONF_DATTO_DEBUG);
        $this->enable(self::CONF_DATTO_DEBUG_PRINTER);
    }

    public function disableDebug(): void
    {
        $this->disable(self::CONF_DATTO_DEBUG);
        $this->disable(self::CONF_DATTO_DEBUG_PRINTER);
    }

    public function restartService(): void
    {
        $this->systemctl->restart(self::SERVICE_NAME);
    }

    private function enable(string $configName): void
    {
        $this->telegrafConfigToggler->enable($configName);
    }

    private function disable(string $configName): void
    {
        $this->telegrafConfigToggler->disable($configName);
    }
}
