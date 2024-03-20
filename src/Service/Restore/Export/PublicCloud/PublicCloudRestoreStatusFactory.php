<?php

namespace Datto\Service\Restore\Export\PublicCloud;

/**
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudRestoreStatusFactory
{
    public function create()
    {
        return new PublicCloudRestoreStatus();
    }
}
