<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Job\JobService;
use Datto\Asset\Agent\Job\Serializer\AgentJobListSerializer;
use Datto\Asset\Agent\Log\LogService;
use Datto\Asset\Agent\Log\Serializer\AgentLogsSerializer;
use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;

/**
 * Handles requests to show agent's logs.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class LogsController extends AbstractBaseController
{
    const VDDK_LOG_LINES_SHOWN = 100;

    private AgentService $agentService;
    private LogService $logService;
    private JobService $jobService;
    private AgentLogsSerializer $agentLogsSerializer;
    private AgentJobListSerializer $agentJobListSerializer;

    public function __construct(
        NetworkService $networkService,
        AgentService $agentService,
        LogService $logService,
        JobService $jobService,
        AgentLogsSerializer $agentLogsSerializer,
        AgentJobListSerializer $agentJobListSerializer,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->agentService = $agentService;
        $this->logService = $logService;
        $this->jobService = $jobService;
        $this->agentLogsSerializer = $agentLogsSerializer;
        $this->agentJobListSerializer = $agentJobListSerializer;
    }

    /**
     * Renders the index of the Agent's log page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP_LOGS_READ")
     *
     * @param $agentName
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($agentName)
    {
        $agent = $this->agentService->get($agentName);
        $logs = $this->logService->get($agent);
        $jobLists = array();

        if (!$agent->getPlatform()->isAgentless()) {
            $jobLists = $this->jobService->get($agent);
        }

        $serializedLogs = array();
        $serializedJobLists = array();

        foreach ($logs as $log) {
            $serializedLogs[] = $this->agentLogsSerializer->serialize($log);
        }

        // The current PHP memory limit is 128Mb. The size of this array causes out of memory
        // errors during rendering so it must be unset.
        unset($logs);

        foreach ($jobLists as $jobList) {
            $serializedJobLists[] = $this->agentJobListSerializer->serialize($jobList);
        }

        //Serializing first to arrays.
        return $this->render('Agents/Logs/index.html.twig', array(
            "hostname" => $agent->getHostname(),
            "logs" => $serializedLogs,
            "joblists" => $serializedJobLists,
            "platform" => $agent->getPlatform()
        ));
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP_LOGS_READ")
     */
    public function vddkAction(): \Symfony\Component\HttpFoundation\Response
    {
        $logs = $this->logService->readVddkLogs(self::VDDK_LOG_LINES_SHOWN);

        return $this->render('Agents/Logs/vddk.html.twig', [
            'logs' => $logs
        ]);
    }
}
