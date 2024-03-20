<?php

namespace Datto\Restore\Export\Usb;

use Datto\Utility\File\Lock;

class UsbLock extends Lock
{
    const LOCK_FILE = '/dev/shm/usbSnapLock';

    public function __construct()
    {
        parent::__construct(self::LOCK_FILE);
    }
}
