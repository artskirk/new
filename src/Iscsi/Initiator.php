<?php

namespace Datto\Iscsi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException as PFE;

/**
 * Performs some basic initiator tasks and retrieves information about
 * connected targets
 *
 * @author Justin Giacobbi
 */
class Initiator
{
    const ISCSIADM = "iscsiadm";
    const DISK_BY_PATH = "/dev/disk/by-path";

    // iscsiadm exit codes. See `man iscsiadm`
    const NONE_FOUND = 21; // no records/targets/sessions/portals found to execute operation on
    const SESSION_EXISTS = 15; // session is logged in

    /** @var Filesystem */
    private $filesystem;

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(Filesystem $filesystem = null, ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->filesystem = $filesystem ?? new Filesystem($this->processFactory);
    }

    /**
     * Returns an array of all targets at the specified IP.
     * Format is: IP:PORT,1 TARGET_NAME
     *
     * @param string $ip
     */
    public function discoverByIP($ip): array
    {
        $process = $this->processFactory->get([self::ISCSIADM, '--mode', 'discovery', '--type', 'sendtargets', '--portal', $ip]);

        $process->mustRun();

        return array_filter(explode("\n", trim($process->getOutput())));
    }

    /**
     * Lists all known records. Example entry:
     * 10.0.22.93:3260,1 iqn.2002-12.com.datto:f11e53aa1d2f11e6b21c0800277452ce
     */
    public function listRecords(): array
    {
        $process = $this->processFactory->get([self::ISCSIADM, '--mode', 'node']);

        try {
            $process->mustRun();
        } catch (PFE $e) {
            if ($e->getProcess()->getExitCode() === self::NONE_FOUND) {
                return [];
            }
            throw $e;
        }

        return array_filter(explode("\n", trim($process->getOutput())));
    }

    /**
     * Returns an array of LUN to block device for a given target
     *
     * @param string $target
     * @return array
     */
    public function getBlockDeviceOfTarget($target)
    {
        $result = array();

        $targets = $this->filesystem->glob(self::DISK_BY_PATH . "/*");
        $targets = preg_grep("#$target#", $targets);

        foreach ($targets as $path) {
            unset($matches);
            preg_match('#[\d]+$#', $path, $matches);

            if (!isset($matches[0])) {
                continue;
            }

            $lun = $matches[0];

            $result[$lun] = $this->filesystem->realpath($path);
        }

        return $result;
    }

    /**
     * Removes a database entry for a specified host
     *
     * @param string $ip
     */
    public function clearDiscoveryDatabaseEntry($ip)
    {
        $this->processFactory->get([self::ISCSIADM, '--mode', 'discoverydb', '--type', 'sendtargets', '--portal', $ip, '--op', 'delete'])
            ->run();
    }

    /**
     * Logs into an iscsi target
     *
     * @param string $target the target to log into
     */
    public function loginTarget(string $target)
    {
        $process = $this->processFactory->get([self::ISCSIADM, '--mode', 'node', '--targetname', $target, '--login']);

        try {
            $process->mustRun();
        } catch (PFE $e) {
            if ($e->getProcess()->getExitCode() === self::SESSION_EXISTS) {
                return; // No error. We're already logged in
            }
            throw $e;
        }
    }

    /**
     * Logs out of an iscsi target
     *
     * @param string $target The target to log out of
     */
    public function logoutTarget(string $target)
    {
        $process = $this->processFactory->get([self::ISCSIADM, '--mode', 'node', '--targetname', $target, '--logout']);

        try {
            $process->mustRun();
        } catch (PFE $e) {
            if ($e->getProcess()->getExitCode() === self::NONE_FOUND) {
                return; // No error. We're already logged out
            }
            throw $e;
        }
    }

    /**
     * Logs out of all iscsi targets by ip
     *
     * @param string|null $ip
     */
    public function logoutIP($ip)
    {
        $commandLine = [self::ISCSIADM, '--mode', 'node'];
        if ($ip !== null) {
            $commandLine[] = '--portal';
            $commandLine[] = $ip;
        }
        $commandLine[] = '--logout';

        $process = $this->processFactory->get($commandLine);

        try {
            $process->mustRun();
        } catch (PFE $e) {
            if ($e->getProcess()->getExitCode() === self::NONE_FOUND) {
                return; // No error. We're already logged out
            }
            throw $e;
        }
    }
}
