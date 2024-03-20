<?php

namespace Datto\Asset\Share\Nas;

use Datto\Asset\Asset;
use Datto\Asset\Share\ShareException;
use Datto\Samba\SambaManager;
use Datto\Samba\SambaShare;

/**
 * Base settings class for NAS shares, to provide access
 * to the underlying share name, and the samba manager.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class AbstractSettings
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $mountPath;

    /** @var SambaManager */
    protected $samba;

    /**
     * @param string $name share name
     * @param SambaManager $samba
     */
    public function __construct($name, SambaManager $samba)
    {
        $this->name = $name;
        $this->mountPath = Asset::BASE_MOUNT_PATH . '/' . $name;
        $this->samba = $samba;
    }

    /**
     * @return SambaShare the samba share of this NAS share
     */
    public function getSambaShare(): SambaShare
    {
        $this->samba->reload();
        $sambaShare = $this->samba->getShareByName($this->name);

        if ($sambaShare === null) {
            throw new ShareException("unable to load {$this->name}, samba share does not exist");
        }

        return $sambaShare;
    }
}
