<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Asset\Agent\AgentService;
use Datto\Reporting\Aggregated\ReportService;

/**
 * This class contains the API endpoints for updating
 * the offsite settings for assets.
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
 * @author Chad Kosie <ckosie@datto.com>
 */
class Report
{
    /** @var AgentService */
    private $agentService;

    /** @var ReportService */
    private $reportService;

    public function __construct(AgentService $agentService, ReportService $reportService)
    {
        $this->agentService = $agentService;
        $this->reportService = $reportService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_BACKUP_REPORTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BACKUP_REPORT_READ")
     * @param string $assetKey
     * @param string $timeframe
     * @return array
     */
    public function getReport(string $assetKey, string $timeframe): array
    {
        $earliestEpoch = $this->reportService->getEpochFromTimeframe($timeframe);
        $agent = $this->agentService->get($assetKey);
        $report = $this->reportService->getReport($agent, $earliestEpoch);

        return [
            'report' => $report->toArray()
        ];
    }
}
