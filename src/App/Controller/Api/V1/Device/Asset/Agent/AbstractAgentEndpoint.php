<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Datto\Asset\Agent\AgentService;

/**
 * This class encapsulates all common logic for setting
 * up an API endpoint for all agent including setting up the
 * agent service.
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
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
abstract class AbstractAgentEndpoint extends AbstractController
{
    /** @var AgentService */
    protected $agentService;

    public function __construct(AgentService $agentService)
    {
        $this->agentService = $agentService;
    }
}
