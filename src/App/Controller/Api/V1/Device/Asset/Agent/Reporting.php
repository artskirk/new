<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Alert\AlertManager;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This class contains the API endpoints for working with agent reporting.
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
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class Reporting implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AlertManager */
    private $alertManager;

    public function __construct(AlertManager $alertManager)
    {
        $this->alertManager = $alertManager;
    }
    /**
     * Enable email and screen alerts being reported by the agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "name" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $name
     * @return array
     */
    public function enableAlerts($name): array
    {
        $this->alertManager->disableAssetSuppression($name);

        $this->logger->setAssetContext($name);
        $this->logger->info('ALE0001 Enabled alerts'); // log code is used by device-web see DWI-2252

        return array (
            'isAlertNotificationEnabled' => true
        );
    }

    /**
     * Disable email and screen alerts being reported by the agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "name" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $name
     * @return array
     */
    public function disableAlerts($name): array
    {
        $this->alertManager->enableAssetSuppression($name);

        $this->logger->setAssetContext($name);
        $this->logger->info('ALE0002 Disabled alerts'); // log code is used by device-web see DWI-2252

        return array (
            'isAlertNotificationEnabled' => false
        );
    }
}
