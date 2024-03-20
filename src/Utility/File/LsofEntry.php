<?php

namespace Datto\Utility\File;

/**
 * Represents an entry of the 'lsof' output.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LsofEntry
{
    /** @var string */
    private $command;

    /** @var int */
    private $pid;

    /** @var string */
    private $user;

    /** @var string */
    private $fd;

    /** @var string */
    private $type;

    /** @var string */
    private $device;

    /** @var string */
    private $size;

    /** @var string */
    private $node;

    /** @var string */
    private $name;

    /**
     * @param $command
     * @param $pid
     * @param $user
     * @param $fd
     * @param $type
     * @param $device
     * @param $size
     * @param $node
     * @param $name
     */
    public function __construct($command, $pid, $user, $fd, $type, $device, $size, $node, $name)
    {
        $this->command = $command;
        $this->pid = $pid;
        $this->user = $user;
        $this->fd = $fd;
        $this->type = $type;
        $this->device = $device;
        $this->size = $size;
        $this->node = $node;
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string
     */
    public function getFd()
    {
        return $this->fd;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @return string
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return string
     */
    public function getNode()
    {
        return $this->node;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
