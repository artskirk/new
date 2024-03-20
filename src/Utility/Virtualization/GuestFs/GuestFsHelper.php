<?php

namespace Datto\Utility\Virtualization\GuestFs;

abstract class GuestFsHelper extends GuestFsErrorHandler
{
    protected ?GuestFs $guestFs = null;

    public function __construct(GuestFs $guestFs)
    {
        $this->guestFs = $guestFs;
    }

    /**
     * Return the handle to the underlying libguestfs
     *
     * @return resource
     * @throws GuestFsException
     */
    protected function getHandle()
    {
        $handle = $this->guestFs->getHandle() ?? null;
        if ($handle === null) {
            throw new GuestFsException('GuestFs not initialized');
        }
        return $handle;
    }
}
