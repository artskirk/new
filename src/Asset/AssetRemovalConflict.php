<?php

namespace Datto\Asset;

use Throwable;

/**
 * Thrown if an asset cannot be removed due to a conflict (eg. has an existing restore, etc.)
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AssetRemovalConflict extends \Exception
{
    const CODE = 409;

    /**
     * @param string $message
     * @param Throwable|null $previous
     */
    public function __construct($message = "", Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $previous);
    }
}
