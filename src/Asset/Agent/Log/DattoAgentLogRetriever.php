<?php

namespace Datto\Asset\Agent\Log;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Log\LoggerFactory;
use Throwable;

/**
 * Retrieves Log information for a Datto Agent
 *
 * @author John Roland <jroland@datto.com>
 */
class DattoAgentLogRetriever implements Retriever
{
    const DEFAULT_LOG_LINE_COUNT = 1000;
    const DEFAULT_LOG_SEVERITY = 2;

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
     * Get log information for a Datto Agent
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
            $agentApi = $this->agentApiFactory->createFromAgent($this->agent);
            $rawLogs = $agentApi->getAgentLogs($severity, $lineCount) ?? [];
        } catch (Throwable $e) {
            $logger = $this->loggerFactory->getAsset($this->agent->getKeyName());
            $logger->error('DLR0001 Unexpected exception retrieving logs from Datto agent', ['exception' => $e]);
        }
        $rawLogs['log'] = $rawLogs['log'] ?? [];
        foreach ($rawLogs['log'] as $log) {
            $timestamp = $log['timestamp'];
            $code = null;
            $message = $log['message'];
            $severity = $log['severity'];
            $agentLogs[] = new AgentLog($timestamp, $code, $message, $severity);
        }

        return $agentLogs;
    }
}
