<?php

namespace Datto\BMR;

use Datto\Asset\Agent\Agent;
use Datto\Log\LoggerFactory;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Common\Utility\Filesystem;

/**
 * Class that handles a BMR result report
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class ResultService
{
    const FAILURE_FILE_SUFFIX = ".failure";
    const SUCCESS_FILE_SUFFIX = ".success";
    const BMR_TAG = "bmr";

    /** @var Filesystem */
    private $filesystem;

    /** @var Collector */
    private $collector;

    /**
     * @param Filesystem $filesystem
     * @param Collector $collector
     */
    public function __construct(Filesystem $filesystem, Collector $collector)
    {
        $this->filesystem = $filesystem;
        $this->collector = $collector;
    }

    /**
     * Records the results of the BMR attempt context to kibana and records the BMR
     * general log in the agent metadata directory
     *
     * @param Agent $agent
     * @param array $report
     * @param bool $success
     * @param string|null $macAddress
     * @return bool
     */
    public function record(Agent $agent, array $report, bool $success, string $macAddress = null): bool
    {
        $agentName = $agent->getKeyName();

        $logger = LoggerFactory::getAssetLogger($agentName);

        if ($success) {
            $logger->info('BMR0001 BMR completed and reported', ['reportMessage' => $report['message']]);
        } else {
            $logger->error('BMR0000 BMR failed and reported', ['reportMessage' => $report['message']]);
        }

        if (!empty($report["log"])) {
            $stateMap = $report["stateMap"];
            $snapshot = $stateMap["point"];
            $this->filesystem->filePutContents(
                $this->getLogPath($agentName, $success, $snapshot, $macAddress),
                $this->unpackLog($report["log"])
            );
        }

        $metric = $success ? Metrics::RESTORE_BMR_PROCESS_SUCCESS : Metrics::RESTORE_BMR_PROCESS_FAILURE;
        $tags = [
            'is-replicated' => $agent->getOriginDevice()->isReplicated(),
            'is-cloud' => $agent->isDirectToCloudAgent(),
        ];
        try {
            $this->collector->increment($metric, $tags);
        } catch (\Throwable $e) {
            $logger->error('BMR0002 Could not log success metric', ['exception' => $e]);
        }

        return true;
    }

    /**
     * @param string $log
     * @return string
     */
    protected function unpackLog(string $log): string
    {
        return gzuncompress(base64_decode($log));
    }

    /**
     * Get the bmr result log path for a given agent
     *
     * @param string $keyName
     * @param bool $success
     * @param string|null $snapshot
     * @param string|null $macAddress
     * @return string
     */
    public function getLogPath(string $keyName, bool $success, string $snapshot = null, string $macAddress = null): string
    {
        $fileSuffix = ($success) ? self::SUCCESS_FILE_SUFFIX : self::FAILURE_FILE_SUFFIX;
        $fileName = Agent::KEYBASE . $keyName;
        if (!(empty($snapshot))) {
            $fileName .= "-" . $snapshot;
        }
        if (!(empty($macAddress))) {
            $fileName .= "-" . $macAddress;
        }
        $fileName .= "-" . self::BMR_TAG;
        return $fileName . $fileSuffix;
    }
}
