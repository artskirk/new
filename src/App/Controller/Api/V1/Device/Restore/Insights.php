<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\Asset\Agent\Serializer\AgentSerializer;
use Datto\Restore\Insight\InsightsService;

/**
 * API endpoint for backup insights
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
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Insights
{
    /** @var InsightsService */
    private $insightsService;

    /** @var AgentSerializer */
    private $serializer;

    public function __construct(InsightsService $insightsService, AgentSerializer $serializer)
    {
        $this->insightsService = $insightsService;
        $this->serializer = $serializer;
    }

    /**
     * Returns all comparable agents
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @return string[]
     */
    public function getComparableAgents(): array
    {
        $agents = $this->insightsService->getComparableAgents();
        $serializedAgents = [];
        foreach ($agents as $agent) {
            $serializedAgent = $this->serializer->serialize($agent);

            $serializedAgents[] = $serializedAgent;
        }

        return $serializedAgents;
    }

    /**
     * Cleans comparison for the specified agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey Name of the agent
     * @return bool
     */
    public function remove(string $agentKey): bool
    {
        $this->insightsService->remove($agentKey);

        return true;
    }

    /**
     * Checks if a comparison exists for the specified agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey Name of the agent
     * @return bool
     */
    public function exists(string $agentKey): bool
    {
        return $this->insightsService->exists($agentKey);
    }

    /**
     * Starts comparison.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey Name of the agent
     * @param int $firstPoint
     * @param int $secondPoint
     * @return bool
     */
    public function start(string $agentKey, int $firstPoint, int $secondPoint): bool
    {
        $this->insightsService->start($agentKey, $firstPoint, $secondPoint, true);

        return true;
    }

    /**
     * Get comparison results.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey Name of the agent
     * @param int $firstPoint
     * @param int $secondPoint
     * @return array
     */
    public function getResults(string $agentKey, int $firstPoint, int $secondPoint): array
    {
        $results = $this->insightsService->getResults($agentKey, $firstPoint, $secondPoint);
        $results->loadResults();

        return $results->toArray();
    }

    /**
     * Get status of an in progress comparison.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey Name of the agent
     * @return string[]
     */
    public function getStatus(string $agentKey): array
    {
        return $this->insightsService->getStatus($agentKey)->toArray();
    }
}
