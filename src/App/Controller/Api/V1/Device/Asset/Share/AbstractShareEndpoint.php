<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share;

use Datto\Asset\Share\ShareService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This class encapsulates all common logic for setting
 * up an API endpoint for shares including setting up the
 * share service.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author John Roland <jroland@datto.com>
 */
abstract class AbstractShareEndpoint implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var ShareService */
    protected $shareService;

    public function __construct(ShareService $shareService)
    {
        $this->shareService = $shareService;
    }
}
