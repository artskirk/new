<?php

namespace Datto\App\Controller\Api\V1\Device\Bmr;

use Datto\Asset\Agent\AgentService;
use Datto\BMR\ResultService;

/**
 * API endpoint for BMR concerns.
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
 * @author Justin Giacobbi <justin@datto.com>
 */
class Results
{
    /** @var AgentService */
    private $agentService;

    /** @var ResultService */
    private $resultService;

    public function __construct(
        AgentService $agentService,
        ResultService $resultService
    ) {
        $this->agentService = $agentService;
        $this->resultService = $resultService;
    }

    /**
     * Record detailed failure information
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @param string $agentName Name of the agent
     * @param array $report The detailed report
     * @param string|null $macAddress MAC Address of the target machine
     * @return bool
     */
    public function recordFailure(string $agentName, array $report, string $macAddress = null): bool
    {
        return $this->resultService->record($this->agentService->get($agentName), $report, false, $macAddress);
    }

    /**
     * Record detailed success information
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @param string $agentName Name of the agent
     * @param array $report The detailed report
     * @param string|null $macAddress MAC Address of the target machine
     * @return bool
     */
    public function recordSuccess(string $agentName, array $report, string $macAddress = null): bool
    {
        return $this->resultService->record($this->agentService->get($agentName), $report, true, $macAddress);
    }
}
