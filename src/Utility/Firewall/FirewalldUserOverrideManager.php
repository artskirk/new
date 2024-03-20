<?php

namespace Datto\Utility\Firewall;

use Datto\Common\Resource\Filesystem;
use Datto\Util\IniTranslator;
use Datto\Utility\File\LockFactory;

/**
 * This class manages Firewalld user override ports and services.
 * The ini file FIREWALLD_USER_OVERRIDE_FILE contains ports and services that support has manually configured
 * for datto zone via [snapctl firewall:*] commands. Otherwise all non standard datto zone ports and services are reset
 * by firewalld code when it is applied. Zones and ports referred in this file will be retained by firewalld code by
 * applying the rules contained in this file at the end of all rules.
 *
 * File format:
 * [datto]
 * ports = "102/tcp 103/udp"
 * services = "klogin kibana"
 *
 * @author Alex Joseph <ajoseph@datto.com>
 */
class FirewalldUserOverrideManager
{
    public const FIREWALLD_USER_OVERRIDE_FILE = '/datto/config/firewalld-user-override.ini';
    private const FIREWALLD_USER_OVERRIDE_LOCK = '/dev/shm/firewalldUserOverride.lock';
    private const SERVICES = 'services';
    private const PORTS = 'ports';

    private Filesystem $filesystem;
    private IniTranslator $iniTranslator;
    private LockFactory $lockFactory;

    public function __construct(
        Filesystem $filesystem,
        IniTranslator $iniTranslator,
        LockFactory $lockFactory
    ) {
        $this->filesystem = $filesystem;
        $this->iniTranslator = $iniTranslator;
        $this->lockFactory = $lockFactory;
    }

    /**
     * Gets user override ports for a given zone.
     * @return string[]
     */
    public function getOverridePorts(string $zone): array
    {
        $retPorts = [];
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            return $retPorts;
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (isset($iniContents[$zone][self::PORTS])) {
            $retPorts =  preg_split('/\s+/', $iniContents[$zone][self::PORTS]);
        }

        return $retPorts;
    }

    /**
     * Gets user override firewalld services for a given zone.
     * @return string[]
     */
    public function getOverrideServices(string $zone): array
    {
        $retServices = [];
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            return $retServices;
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (isset($iniContents[$zone][self::SERVICES])) {
            $retServices = preg_split('/\s+/', $iniContents[$zone][self::SERVICES]);
        }
        return $retServices;
    }

    /**
     * Adds override port of the form "123/tcp" to override file.
     */
    public function addOverridePort(string $zone, string $port) : void
    {
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            $this->filesystem->touch(self::FIREWALLD_USER_OVERRIDE_FILE);
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (!$iniContents) {
            $iniContents = [];
        }

        if (!isset($iniContents[$zone][self::PORTS])) {
            $iniContents[$zone][self::PORTS] = $port;
        } else {
            $existingPorts = preg_split('/\s+/', $iniContents[$zone][self::PORTS]);
            if (!in_array($port, $existingPorts)) {
                $iniContents[$zone][self::PORTS] .= " $port";
            }
        }

        $data = $this->iniTranslator->stringify($iniContents);
        $this->filesystem->filePutContents(self::FIREWALLD_USER_OVERRIDE_FILE, $data);
    }

    /**
     * Removes port of the form "123/tcp" from override file.
     */
    public function removeOverridePort(string $zone, string $port): void
    {
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            return;
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (!$iniContents || !isset($iniContents[$zone]) || !isset($iniContents[$zone][self::PORTS])) {
            return;
        }

        // Remove $port
        $portsArray = preg_split('/\s+/', $iniContents[$zone][self::PORTS]);
        $portsArray = array_filter(
            $portsArray,
            function (string $el) use ($port) {
                return $el !== $port;
            }
        );
        $iniContents[$zone][self::PORTS] = implode(" ", $portsArray);
        if (empty($iniContents[$zone][self::PORTS])) {
            unset($iniContents[$zone][self::PORTS]);
        }

        $data = $this->iniTranslator->stringify($iniContents);
        $this->filesystem->filePutContents(self::FIREWALLD_USER_OVERRIDE_FILE, $data);
    }

    /**
     * Adds specified service to override file.
     */
    public function addOverrideService(string $zone, string $service): void
    {
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            $this->filesystem->touch(self::FIREWALLD_USER_OVERRIDE_FILE);
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (!$iniContents) {
            $iniContents = [];
        }

        if (!isset($iniContents[$zone][self::SERVICES])) {
            $iniContents[$zone][self::SERVICES] = $service;
        } else {
            if (strpos($iniContents[$zone][self::SERVICES], $service) === false) {
                $iniContents[$zone][self::SERVICES] .= " $service";
            }
        }

        $data = $this->iniTranslator->stringify($iniContents);
        $this->filesystem->filePutContents(self::FIREWALLD_USER_OVERRIDE_FILE, $data);
    }

    /**
     * Removes specified service from override file.
     */
    public function removeOverrideService(string $zone, string $service): void
    {
        if (!$this->filesystem->isFile(self::FIREWALLD_USER_OVERRIDE_FILE)) {
            return;
        }

        $lock = $this->lockFactory->create(self::FIREWALLD_USER_OVERRIDE_LOCK);
        $lock->exclusive();

        $iniContents = $this->filesystem->parseIniFile(self::FIREWALLD_USER_OVERRIDE_FILE, true);
        if (!$iniContents || !isset($iniContents[$zone]) || !isset($iniContents[$zone][self::SERVICES])) {
            return;
        }

        // remove service
        $portsArray = preg_split('/\s+/', $iniContents[$zone][self::SERVICES]);
        $portsArray = array_filter(
            $portsArray,
            function (string $el) use ($service) {
                return $el !== $service;
            }
        );
        $iniContents[$zone][self::SERVICES] = implode(" ", $portsArray);
        if (empty($iniContents[$zone][self::SERVICES])) {
            unset($iniContents[$zone][self::SERVICES]);
        }

        $data = $this->iniTranslator->stringify($iniContents);
        $this->filesystem->filePutContents(self::FIREWALLD_USER_OVERRIDE_FILE, $data);
    }
}
