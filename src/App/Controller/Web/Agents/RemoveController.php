<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DataProvider;
use Datto\Asset\Agent\EncryptionService;
use Datto\Common\Resource\Filesystem;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\RestoreService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles requests for the remove agents page.
 *
 * @author Chad Kosie <ckosie@datto.com>
 * @deprecated remove after clf if the only one true UI
 */
class RemoveController extends AbstractBaseController
{
    private AgentService $service;
    private RestoreService $restoreService;
    private InsightsService $insightsService;
    private EncryptionService $encryptionService;
    private DataProvider $agentDataProvider;

    public function __construct(
        NetworkService $networkService,
        AgentService $service,
        RestoreService $restoreService,
        InsightsService $insightsService,
        EncryptionService $encryptionService,
        ClfService $clfService,
        Filesystem $filesystem,
        DataProvider $agentDataProvider
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->service = $service;
        $this->restoreService = $restoreService;
        $this->insightsService = $insightsService;
        $this->encryptionService = $encryptionService;
        $this->agentDataProvider = $agentDataProvider;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_DELETE")
     *
     * @param string $agentName
     * @return RedirectResponse|Response
     */
    public function indexAction(string $agentName): Response
    {
        if (!$this->service->exists($agentName)) {
            return $this->redirect($this->generateUrl('agents_index'));
        }

        $agent = $this->service->get($agentName);

        $restores = $this->agentDataProvider->getRestores($agentName);
        $comparisons = $this->agentDataProvider->getComparison($agent);
        return $this->render('Agents/Remove/index.html.twig', [
            'assetKey' => $agent->getKeyName(),
            'displayName' => $agent->getDisplayName(),
            'restores' => $restores,
            'comparisons' => $comparisons,
        ]);
    }
}
