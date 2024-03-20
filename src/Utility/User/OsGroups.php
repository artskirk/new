<?php

namespace Datto\Utility\User;

use Datto\Common\Resource\ProcessFactory;

/**
 * Get Linux user groups using the standard "groups" command.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class OsGroups
{
    /** @var ProcessFactory */
    private $processFactory;

    /**
     * @param ProcessFactory|null $processFactory
     */
    public function __construct(
        ProcessFactory $processFactory = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * Get the groups to which a user belongs
     *
     * @param string $username
     * @return string[]
     */
    public function getGroups(string $username): array
    {
        $commandLine = ['groups', $username];

        $process = $this->processFactory->get($commandLine);
        $process->mustRun();

        $output = $process->getOutput();

        return $this->parseOutput($output);
    }

    /**
     * Parses the output of the groups command which looks like:
     *  backup-admin : backup-admin libvirtd remote-users
     *
     * @param string $output
     * @return string[]
     */
    private function parseOutput(string $output): array
    {
        $parts = explode(":", $output);
        $groups = trim($parts[1] ?? '');
        return $groups ? explode(" ", $groups) : [];
    }
}
