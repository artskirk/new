<?php

namespace Datto\Asset\Agent\Log;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Log\LoggerFactory;
use Throwable;

/**
 * Retrieve logs for Shadow Snap agents.
 *
 * @author John Roland <jroland@datto.com>
 */
class ShadowProtectLogRetriever implements Retriever
{
    const DEFAULT_LOG_LINE_COUNT = 50;
    const DEFAULT_LOG_SEVERITY = 0;

    /** @var Agent */
    private $agent;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var LoggerFactory */
    private $loggerFactory;

    public function __construct(
        Agent $agent,
        AgentApiFactory $agentApiFactory = null,
        LoggerFactory $loggerFactory = null
    ) {
        $this->agent = $agent;
        $this->agentApiFactory = $agentApiFactory ?: new AgentApiFactory();
        $this->loggerFactory = $loggerFactory ?: new LoggerFactory();
    }

    /**
     * Retrieve logs for Shadow Snap agents.
     *
     * @param int|null $lineCount
     * @param int|null $severity
     * @return AgentLog[]
     */
    public function get(int $lineCount = null, int $severity = null)
    {
        $lineCount = $lineCount ?? self::DEFAULT_LOG_LINE_COUNT;
        $severity = $severity ?? self::DEFAULT_LOG_SEVERITY;

        $agentLogs = [];
        try {
            $shadowSnapAgentApi = $this->agentApiFactory->createFromAgent($this->agent);
            $rawLogs = $shadowSnapAgentApi->getAgentLogs($severity, $lineCount);
        } catch (Throwable $e) {
            $logger = $this->loggerFactory->getAsset($this->agent->getKeyName());
            $logger->error('SLR0001 Unexpected exception retrieving logs from ShadowSnap agent', ['exception' => $e]);
        }
        $rawLogs['log'] = $rawLogs['log'] ?? [];
        foreach ($rawLogs['log'] as $log) {
            $date = $log['created'];
            $code = $log['code'];
            $message = $log['message'];
            $severity = $log['severity'];
            $agentLogs[] = new AgentLog($date, $code, $message, $severity);
        }

        return $agentLogs;
    }
}
