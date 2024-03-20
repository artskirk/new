<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Utility\Security\SecretString;

/**
 * This class contains the API endpoints for enabling/disabling temporary agent troubleshooting access.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class TempAccess
{
    /**
     * @var TempAccessService
     */
    protected $tempAccessService;

    public function __construct(TempAccessService $tempAccessService)
    {
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Enable temporary troubleshooting access for an encrypted agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Agent name
     * @param string $password Agent encryption key
     * @return array 'disableTime' is the expiration time in seconds
     */
    public function enable(string $agentName, string $password)
    {
        $password = new SecretString($password);
        $disableTime = $this->tempAccessService->enableCryptTempAccess($agentName, $password);
        return [
            'disableTime' => $disableTime
        ];
    }

    /**
     * Disable temporary troubleshooting access for an encrypted agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Agent name
     * @param string $password Agent encryption key
     */
    public function disable(string $agentName, string $password): void
    {
        $password = new SecretString($password);
        $this->tempAccessService->disableCryptTempAccess($agentName, $password);
    }
}
