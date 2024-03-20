<?php

namespace Datto\System;

/**
 * Interface MountableInterface
 *
 * Objects which model a resource which can be mounted can implement this interface
 * and utilize the MountManager for mounting and unmounting. It can be used for non-block devices.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
interface MountableInterface
{
    /**
     * The linux 'mount' command arguments required to mount the resource
     * Returns an array of arguments passed to the mount command, all except the mount directory.
     *
     * @return array
     */
    public function getMountArguments();
}
