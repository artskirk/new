<?php

namespace Datto\App\Controller\Api\V1\Device\Bmr;

use Datto\Asset\Agent\AgentService;
use Datto\Config\DeviceConfig;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;

/**
 * API endpoint for logging BMR metrics.
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
 * @author Peter Geer <pgeer@datto.com>
 */
class Logging
{
    const METRIC_TYPE_MAP =[
        'transferStart' => Metrics::RESTORE_BMR_TRANSFER_STARTED,
        'transferSuccess' => Metrics::RESTORE_BMR_TRANSFER_SUCCESS,
        'transferFailure' => Metrics::RESTORE_BMR_TRANSFER_FAILURE,
        'mirrorStart' => Metrics::RESTORE_BMR_MIRROR_STARTED,
        'mirrorSuccess' => Metrics::RESTORE_BMR_MIRROR_SUCCESS,
        'mirrorFailure' => Metrics::RESTORE_BMR_MIRROR_FAILURE,
        'hirStart' => Metrics::RESTORE_BMR_HIR_STARTED,
        'hirSuccess' => Metrics::RESTORE_BMR_HIR_SUCCESS,
        'hirFailure' => Metrics::RESTORE_BMR_HIR_FAILURE,
        'bmrSuccess' => Metrics::RESTORE_BMR_PROCESS_SUCCESS,
        'bmrFailure' => Metrics::RESTORE_BMR_PROCESS_FAILURE,
    ];

    /** @var Collector */
    private $collector;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var AgentService */
    private $agentService;

    public function __construct(Collector $collector, DeviceConfig $deviceConfig, AgentService $agentService)
    {
        $this->collector = $collector;
        $this->deviceConfig = $deviceConfig;
        $this->agentService = $agentService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "type" = @Symfony\Component\Validator\Constraints\Choice(choices = {
     *         "transferStart", "transferSuccess", "transferFailure",
     *         "mirrorStart", "mirrorSuccess", "mirrorFailure",
     *         "hirStart", "hirSuccess", "hirFailure",
     *         "bmrSuccess", "bmrFailure"
     *     }),
     *     "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $type
     * @param string $agentKey
     * @param int $snapshot
     * @param string $extension
     * @return bool
     */
    public function metric(string $type, string $agentKey, int $snapshot, string $extension)
    {
        $metric = self::METRIC_TYPE_MAP[$type];
        $agent = $this->agentService->get($agentKey);
        $tags = [
            'is-replicated' => $agent->getOriginDevice()->isReplicated(),
            'is-cloud' => $agent->isDirectToCloudAgent(),
        ];
        $this->collector->increment($metric, $tags);
        return true;
    }
}
