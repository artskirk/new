<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\App\Controller\Api\V1\Device;
use Datto\App\Controller\Api\V1\Device\Asset\Agent;
use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Billing;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Controller that renders the agents list (Protect Page)
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ListController extends AbstractBaseController
{
    private Billing\Service $billingService;
    private DeviceConfig $deviceConfig;
    private Device $device;
    private Agent $agent;

    public function __construct(
        NetworkService $networkService,
        Billing\Service $billingService,
        DeviceConfig $deviceConfig,
        Device $device,
        Agent $agent,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->billingService = $billingService;
        $this->deviceConfig = $deviceConfig;
        $this->device = $device;
        $this->agent = $agent;
    }

    /**
     * Renders the list of agents.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     *
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request): Response
    {
        $searchText = $request->query->get('search');
        $keyName = $request->query->get('name');
        $pagination = $this->getPaginationConfig();

        $agentData = $this->getAgentData($pagination, $searchText, $keyName);

        if ($agentData['totalUnfiltered'] === 0 && $searchText === null && $keyName === null) {
            return $this->redirectToRoute('agents_add');
        }

        return $this->render('Agents/List/index.html.twig', [
            'isVirtual' => $this->deviceConfig->has('isVirtual'),
            'isOutOfService' => $this->billingService->isOutOfService(),
            'paginationConfig' => $pagination,
            'backupsPaused' => $this->deviceConfig->get('maxBackups') === '0',
            'newAgent' => $request->query->get('newAgent', ''),
            'searchText' => $searchText,
            'agentKeyName' => $keyName,
            'indexUrl' => $this->generateUrl('agents_index'),
            'deviceInfo' => $this->device->get(),
            'agentData' => $agentData
        ]);
    }

    /**
     * Forwards an external agent search request. Only forwards the search parameter
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     *
     * @param Request $request
     * @return Response
     */
    public function externalRequestAction(Request $request): Response
    {
        return $this->redirectToRoute(
            'agents_index',
            [
                'search' => $request->query->get('search'),
                'name' => $request->query->get('name')
            ]
        );
    }

    private function getPaginationConfig(): array
    {
        $cachedConfig = json_decode($this->deviceConfig->get(DeviceConfig::KEY_PAGINATION_SETTINGS, '{}'), true);
        $defaultConfig = [
            'pagination_sort' => 'hostname',
            'agent_count' => '5',
            'direction' => 'asc',
            'show_archived' => false
        ];

        return array_merge($defaultConfig, $cachedConfig);
    }

    /**
     * We fetch data from the api classes and return it in the first web request to reduce the time the user has to
     * wait until they actually see agents. With this, the js can render the agents right away instead of waiting for
     * another roundtrip through the api.
     */
    private function getAgentData(array $pagination, string $searchText = null, string $keyName = null): array
    {
        if ($keyName !== null) {
            try {
                return [
                    'page' => 1,
                    'pages' => 1,
                    'total' => 1,
                    'agents' => [$this->agent->get($keyName)],
                    'totalUnfiltered' => 1,
                    'totalUnfilteredArchived' => 0
                ];
            } catch (Throwable $e) {
                return [
                    'page' => 1,
                    'pages' => 1,
                    'total' => 0,
                    'agents' => [],
                    'totalUnfiltered' => 0,
                    'totalUnfilteredArchived' => 0
                ];
            }
        }

        $excludes = $pagination['show_archived'] ? [] : [Agent::EXCLUDE_ARCHIVED];
        return $this->agent->getAllFiltered(
            null,
            $pagination['agent_count'],
            $pagination['pagination_sort'],
            $pagination['direction'] === 'asc',
            $excludes,
            [],
            $searchText
        );
    }
}
