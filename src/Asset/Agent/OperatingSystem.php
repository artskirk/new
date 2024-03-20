<?php

namespace Datto\Asset\Agent;

use Datto\Util\OsFamily;

/**
 * Class to represent the operating system properties
 * of an agent.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class OperatingSystem
{
    /** @var OsFamily a grouping of related operating systems*/
    private $osFamily;

    /** @var string Name of the operating system, e.g. Windows 7 */
    private $name;

    /** @var string Version of the operating system, e.g. 6.1.2 */
    private $version;

    /** @var string Architecture used, e.g. x64 */
    private $architecture; // TODO: This should be an ENUM

    /** @var int Number of bits, e.g. 64  */
    private $bits;

    /** @var string If the operating system is Windows, the version of the service pack installed,  */
    private $servicePack;

    /** @var string Kernel version (reported by Linux only) */
    private $kernel;


    /**
     * @param OsFamily $osFamily the related family of the operating system
     * @param string $name Name of the operating system, e.g. Windows 7
     * @param string $version Version of the operating system, e.g. 6.1.2
     * @param string $architecture Architecture used, e.g. x64
     * @param int $bits Number of bits, e.g. 64
     * @param string $servicePack If the operating system is Windows, the version of the service pack installed
     * @param string $kernel Kernel version (reported by Linux only)
     */
    public function __construct(OsFamily $osFamily, $name, $version, $architecture, $bits, $servicePack, $kernel)
    {
        $this->osFamily = $osFamily;
        $this->name = $name;
        $this->version = $version;
        $this->architecture = $architecture;
        $this->bits = $bits;
        $this->servicePack = $servicePack;
        $this->kernel = $kernel;
    }

    /**
     * @return OsFamily
     */
    public function getOsFamily(): OsFamily
    {
        return $this->osFamily;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $name
     */
    public function setName($name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getArchitecture()
    {
        return $this->architecture;
    }

    /**
     * @return int
     */
    public function getBits()
    {
        return $this->bits;
    }

    /**
     * @return string
     */
    public function getServicePack()
    {
        return $this->servicePack;
    }

    /**
     * @return string
     */
    public function getKernel()
    {
        return $this->kernel;
    }
}
