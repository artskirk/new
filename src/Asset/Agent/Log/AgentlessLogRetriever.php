<?php

namespace Datto\Asset\Agent\Log;

use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\AppKernel;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\CurlRequest;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Util\RetryHandler;
use Datto\Utility\File\Tail;
use Datto\Virtualization\VmwareApiClient;
use Datto\Virtualization\VhostFactory;
use GuzzleHttp\Client;

/**
 * Retrieves Log information for an Agentless system
 *
 * @author Matt Cheman <mcheman@datto.com>
 */
class AgentlessLogRetriever implements Retriever
{
    private const DEFAULT_LOG_LINE_COUNT = 1000;
    private const DEFAULT_LOG_SEVERITY = 2;

    private AgentlessSystem $agent;
    private VhostFactory $vhostFactory;
    private VmwareApiClient $vmwareApiClient;
    private Tail $tail;

    public function __construct(
        AgentlessSystem $agent,
        VhostFactory $vhostFactory = null,
        VmwareApiClient $vmwareApiClient = null,
        Tail $tail = null
    ) {
        $this->agent = $agent;
        $this->vhostFactory = $vhostFactory ?: new VhostFactory();
        $this->vmwareApiClient = $vmwareApiClient ?:
            AppKernel::getBootedInstance()->getContainer()->get(VmwareApiClient::class);
        $this->tail = $tail ?: new Tail();
    }

    /**
     * Get log information for an Agentless system
     *
     * @return AgentLog[]
     */
    public function get(int $lineCount = null, int $severity = null)
    {
        $lineCount = $lineCount ?? self::DEFAULT_LOG_LINE_COUNT;
        $severity = $severity ?? self::DEFAULT_LOG_SEVERITY;

        $moRef = $this->agent->getEsxInfo()->getMoRef();
        $connectionName = $this->agent->getEsxInfo()->getConnectionName();
        $vhost = $this->vhostFactory->create($connectionName);
        $uuid = $this->vmwareApiClient->getUuid($vhost);

        $agentlessSession = AgentlessSessionId::create($uuid, $moRef, $this->agent->getKeyName());

        $logPath = sprintf(AgentlessSessionService::SESSION_LOG_PATH_FORMAT, $agentlessSession->toSessionIdName());
        $rawLogs = explode("\n", $this->tail->getLines($logPath, $lineCount));
        $agentLogs = null;

        foreach ($rawLogs as $rawLog) {
            $log = json_decode($rawLog, true);
            if ($log['severity'] < $severity) {
                continue;
            }

            $timestamp = $log['timestamp'];
            $message = $log['message'];
            $severity = $log['severity'];
            $agentLogs[] = new AgentLog($timestamp, "", $message, $severity);
        }

        return $agentLogs ?? [];
    }
}
