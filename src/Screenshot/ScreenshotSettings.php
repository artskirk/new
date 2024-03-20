<?php

namespace Datto\Screenshot;

use Datto\Asset\Agent\OperatingSystem;

/**
 * Screenshot settings for an Agent.
 * Note: currently this class is only used to determine if an agent is screenshottable.
 *      In the future, it may be used to take and retrieve screenshots which means
 *      it may need to be subclassed. For now, it is a concrete class because there is
 *      no difference between the logic for Windows and Linux.
 *
 * @author John Roland <jroland@datto.com>
 */
class ScreenshotSettings
{
    /**
     * @var array list of supported linux distro patterns for screenshotting
     */
    private $supportedLinuxOperatingSystemsPatterns = array(
        '/\bcentos\b/i',    // CentOS
        '/\bdebian\b/i',    // Debian
        '/\bfedora\b/i',    // Fedora
        '/\brhel\b/i',      // Red Hat
        '/\bred\s?hat\b/i', // Red Hat
        '/\bubuntu\b/i',     // Ubuntu
    );

    /**
     * @var array list of supported non-linux distro patterns for screenshotting
     */
    private $supportedNonLinuxOperatingSystemsPatterns = array(
        '/\bwindows\b/i', // Windows
    );

    /**
     * @var array $supportedOperatingSystemsPatterns
     *   List of supported distro/version patterns for screenshotting
     */
    private $supportedOperatingSystemsPatterns;

    /**
     * @var OperatingSystem $operatingSystem
     *   Operating System information (OS name)
     */
    private $operatingSystem;

    /**
     * @var bool $isRescueAgent
     */
    private $isRescueAgent;

    /**
     * @param OperatingSystem $operatingSystem used to determine if screenshots are supported
     * @param bool $isRescueAgent
     */
    public function __construct(OperatingSystem $operatingSystem, $isRescueAgent)
    {
        $this->operatingSystem = $operatingSystem;
        $this->isRescueAgent = $isRescueAgent;

        $this->supportedOperatingSystemsPatterns = array_merge(
            $this->supportedLinuxOperatingSystemsPatterns,
            $this->supportedNonLinuxOperatingSystemsPatterns
        );
    }

    /**
     * Determine if screenshots are supported
     *
     * @return bool True if the screenshots are supported
     */
    public function isSupported()
    {
        return $this->isSupportedOs() && !$this->isRescueAgent;
    }

    /**
     * Determine if screenshots are supported based on the operating system.
     *
     * @return bool True if screenshots are supported, false otherwise.
     */
    protected function isSupportedOs()
    {
        foreach ($this->supportedOperatingSystemsPatterns as $distro) {
            if (preg_match($distro, $this->operatingSystem->getName())) {
                return true;
            }
        }

        return false;
    }
}
