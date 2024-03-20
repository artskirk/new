<?php

namespace Datto\Samba;

/**
 * Business Object that represents clients connected to samba shares.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class SambaClientInfo
{
    /** @var string */
    private $shareName;

    /** @var int */
    private $smbdPid;

    /** @var string */
    private $machineName;

    /** @var int */
    private $connectedAt;

    /**
     * @param string $shareName
     * @param int $smbdPid
     * @param string $machineName
     * @param int $connectedAt
     */
    public function __construct(
        string $shareName,
        int $smbdPid,
        string $machineName,
        int $connectedAt
    ) {
        $this->shareName = $shareName;
        $this->smbdPid = $smbdPid;
        $this->machineName = $machineName;
        $this->connectedAt = $connectedAt;
    }

    /**
     * Get the samba share name client is connected to.
     *
     * @return string
     */
    public function getShareName(): string
    {
        return $this->shareName;
    }

    /**
     * Get the smbd process id that is using the samba share.
     *
     * @return int
     */
    public function getSmbdPid(): int
    {
        return $this->smbdPid;
    }

    /**
     * Get the client machine name connected to the share.
     *
     * @return string
     */
    public function getMachineName(): string
    {
        return $this->machineName;
    }

    /**
     * Get the timestamp that the client connected at.
     *
     * @return int
     */
    public function getConnectedAt(): int
    {
        return $this->connectedAt;
    }
}
