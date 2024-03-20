<?php

namespace Datto\System;

use Datto\Ipmi\IpmiService;
use Datto\Utility\Systemd\Systemctl;

/**
 * This class manages the IPMI watchdog service.
 *
 * For technical details on the ipmi_watchdog driver:
 *
 * Reference: https://www.kernel.org/doc/Documentation/IPMI.txt
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class WatchdogService
{
    const WATCHDOG_CONFIG_FILE = '/etc/watchdog.conf';
    const WATCHDOG_SERVICE = 'watchdog';
    const WATCHDOG_KEEPALIVE_SERVICE = 'wd_keepalive';
    const WATCHDOG_DRIVER = 'ipmi_watchdog';

    /** @var IpmiService */
    private $ipmiService;

    /** @var ModuleManager */
    private $moduleManager;

    /** @var Systemctl */
    private $systemctl;

    /**
     * @param IpmiService $ipmiService
     * @param ModuleManager $moduleManager
     * @param Systemctl $systemctl
     */
    public function __construct(
        IpmiService $ipmiService,
        ModuleManager $moduleManager,
        Systemctl $systemctl
    ) {
        $this->ipmiService = $ipmiService;
        $this->moduleManager = $moduleManager;
        $this->systemctl = $systemctl;
    }

    /**
     * Checks if the ipmi watchdog is active and running
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->ipmiService->isWatchdogEnabled();
    }

    /**
     * Enables the watchdog service
     */
    public function enable()
    {
        // Options are configured in 'files/etc/modprobe.d/ipmi-watchdog.conf'
        $ipmi_watchdog_driver_options = null;

        $this->moduleManager->addModule(self::WATCHDOG_DRIVER, $ipmi_watchdog_driver_options, true);
        $this->systemctl->enable(self::WATCHDOG_SERVICE);
        if ($this->systemctl->isActive(self::WATCHDOG_SERVICE)) {
            $this->systemctl->stop(self::WATCHDOG_SERVICE);
        }
        $this->systemctl->start(self::WATCHDOG_SERVICE);
    }

    /**
     * Disables the watchdog service
     */
    public function disable()
    {
        $this->ipmiService->disableWatchdog();
        $this->systemctl->disable(self::WATCHDOG_SERVICE);
        $this->systemctl->stop(self::WATCHDOG_SERVICE);
        $this->systemctl->stop(self::WATCHDOG_KEEPALIVE_SERVICE);
        $this->moduleManager->removeModule(self::WATCHDOG_DRIVER, true, false);
    }
}
