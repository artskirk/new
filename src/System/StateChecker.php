<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\System\StateCommands\AbstractStateCommand;
use Datto\System\StateCommands\CommandResult;
use Datto\System\StateCommands\SimpleCommand;

/**
 * Runs a bunch of commands to obtain current device state.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class StateChecker
{
    private ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Runs all the commands and returns their output in an array.
     *
     * @return array<string, CommandResult> key if command identifier, value command output
     */
    public function getDeviceStatus(): array
    {
        $results = [];
        $commands = $this->setupCommands();

        foreach ($commands as $command) {
            $key = $command->getCommandIdentifier();
            $result = $command->executeCommand();

            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Creates a list of commands to run.
     *
     * If commands were injected via constructor, it will run the injected ones.
     *
     * @return AbstractStateCommand[]
     */
    private function setupCommands(): array
    {
        $commands = [];
        foreach ($this->getCommands() as $identifier => $command) {
            $commands[] = new SimpleCommand($identifier, $command, $this->processFactory);
        }
        return $commands;
    }

    /**
     * Get a list of commands to run when dumping system state.
     *
     * @return array<string, array<string>>
     */
    private function getCommands(): array
    {
        return [
            'zfs-list' => ['zfs', 'list'],
            'zpool-status' => ['zpool', 'status', 'homePool'],
            'list-loop-devices' => ['losetup', '-a'],
            'list-dm-devices' => ['dmsetup', 'ls'],
            'block-device-list' => ['lsblk'],
            'disk-usage' => ['df', '-h'],
            'package-list' => ['dpkg' ,'-l'],
            'os-version' => ['lsb_release', '-a'],
            'memory-usage' => ['free', '-m'],
            'process-tree' => ['pstree', '-a', '-l'],
            'process-list' => ['ps', 'faux'],
            'speedsync-status' => ['speedsync', 'status'],
            'list-mounts' => ['mount'],
            'kernel-version' => ['uname', '-a'],
            'uptime' => ['uptime'],
            'disk-stats' => ['iostat'],
            'hardware-list' => ['lshw'],
            'iscsi-targets' => ['targetcli', '/', 'ls'],
            'dns-state' => ['dig', 'google.com'],
            'samba-connections' => ['smbstatus', '-v'],
            'services-status' => ['systemctl', 'status'],
            'list-vms' => ['virsh', '-c', 'qemu:///system', 'list', '--all'],
            'resolvectl' => ['resolvectl', 'status'],
            'ip-addr' => ['ip', 'address'],
            'nm-devs' => ['nmcli'],
            'nm-conns' => ['nmcli', '-f', 'all', 'con', 'show']
        ];
    }
}
