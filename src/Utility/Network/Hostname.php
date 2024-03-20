<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Throwable;

/**
 * A simple utility class to get and set the system hostname, and keeps the /etc/hosts file in sync.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class Hostname
{
    public const LOCAL_DOMAIN = 'localdomain';
    private const HOSTS_FILE = '/etc/hosts';

    private ProcessFactory $processFactory;
    private Filesystem $filesystem;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Returns the current system hostname, as stored in /etc/hostname
     * This will return the fully qualified hostname (including ".datto.com") if the machine
     * has a fully qualified hostname in /etc/hostname like cloud devices do.
     * This is called frequently, so this function needs to execute quickly, or there may be performance impacts
     *
     * @return string
     */
    public function get(): string
    {
        $uname = posix_uname();
        return $uname['nodename'];
    }

    /**
     * Get the short hostname of the machine (ie. exclude domain).
     *
     * @return string
     */
    public function getShort(): string
    {
        $process = $this->processFactory
            ->get(['hostname', '-s'])
            ->mustRun();

        return trim($process->getOutput());
    }

    /**
     * Sets the system hostname, and updates the /etc/hosts file with the new hostname
     *
     * @param string $hostname The hostname to set
     */
    public function set(string $hostname)
    {
        $this->setHostname($hostname);
        $this->writeHostsFile($hostname, self::LOCAL_DOMAIN);
    }

    /**
     * Updates the domain component of the hostname in the /etc/hosts file.
     *
     * @param string $domain
     */
    public function updateDomain(string $domain)
    {
        $this->writeHostsFile($this->get(), $domain);
    }

    /**
     * Update the device's hostname
     *
     * @param string $hostname The hostname that you'd like to set the device to use.
     */
    private function setHostname(string $hostname)
    {
        // Use hostnamectl to set the hostname. This will update both the static hostname in /etc/hostname and the
        // transient hostname in the kernel (/proc/sys/kernel/hostname), as well as performing validation of the
        // string passed as hostname.
        $this->processFactory
            ->get(['hostnamectl', 'set-hostname', $hostname])
            ->mustRun();
    }

    /**
     * Update the device's /etc/hosts file
     *
     * @param string $hostname
     * @param string $domain
     */
    private function writeHostsFile(string $hostname, string $domain)
    {
        $hostsFileData =
            '127.0.0.1 localhost' . PHP_EOL .
            "127.0.1.1 $hostname.$domain $hostname" . PHP_EOL;

        $this->filesystem->filePutContents(self::HOSTS_FILE, $hostsFileData);
    }
}
