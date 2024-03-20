<?php

namespace Datto\App\Controller\Web\Advanced;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Restore\Insight\BackupInsight;
use Datto\Restore\Insight\InsightsService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control for the backup insights page
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightsController extends AbstractBaseController
{
    private InsightsService $insightService;

    public function __construct(
        NetworkService $networkService,
        InsightsService $insightService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->insightService = $insightService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     *
     * @return Response
     */
    public function indexAction(): Response
    {
        $activeInsights = $this->insightService->getCurrent();
        $agents = $this->insightService->getComparableAgents();
        $currentCompares = [];

        /** @var BackupInsight $compare */
        foreach ($activeInsights as $compare) {
            $currentCompares[] = [
                "agent"  => $compare->getAgent()->getKeyName(),
                "hostname" => $compare->getAgent()->getHostname(),
                "point1" => $compare->getFirstPoint(),
                "point2" => $compare->getSecondPoint()
            ];
        }

        $infoArray = [];

        foreach ($agents as $agent) {
            $keyName = $agent->getKeyName();

            $infoArray[$keyName]['keyName'] = $keyName;
            $infoArray[$keyName]['displayName'] = $agent->getDisplayName();
            $infoArray[$keyName]['pairName'] = $agent->getPairName();
        }

        return $this->render(
            'Advanced/Insights/index.html.twig',
            [
                'compares' => $currentCompares,
                'infoArray' => $infoArray
            ]
        );
    }
}
