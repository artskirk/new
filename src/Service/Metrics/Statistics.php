<?php

namespace Datto\Service\Metrics;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Resource\DateTimeService;
use Datto\Restore\RestoreService;
use Datto\Log\DeviceLoggerInterface;
use Datto\App\Container\ServiceCollection;

/**
 * Collect periodic device statistics. To collect additional metrics, please extend the Datto\Metrics\Measurement class.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Statistics
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var RestoreService */
    private $restoreService;

    /** @var AgentService */
    private $agentService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var MetricsContext */
    private $context;

    /** @var Measurement[] */
    private $measurements;

    public function __construct(
        array $measurements,
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        AgentService $agentService,
        DateTimeService $dateTimeService,
        MetricsContext $context
    ) {
        $this->measurements = $measurements;
        $this->logger = $logger;
        $this->restoreService = $restoreService;
        $this->agentService = $agentService;
        $this->dateTimeService = $dateTimeService;
        $this->context = $context;
    }

    public static function fromMeasurementCollection(
        ServiceCollection $measurements,
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        AgentService $agentService,
        DateTimeService $dateTimeService,
        MetricsContext $context
    ) {
        return new self(
            $measurements->getAll(),
            $logger,
            $restoreService,
            $agentService,
            $dateTimeService,
            $context
        );
    }

    public function update()
    {
        $this->logger->debug("MSC0001 Collecting statistics ...");

        // start by initializing context with all needed data structures
        $this->logger->debug("MSC0002  - gathering active restores");
        $this->context->setActiveRestores($this->restoreService->getAll());
        $this->logger->debug("MSC0003  - gathering orphaned restores");
        $this->context->setOrphanedRestores($this->restoreService->getOrphans());
        $this->logger->debug("MSC0010  - gathering agents");
        $agents = $this->agentService->getAll();
        $withinLastWeek = $this->dateTimeService->getTime() - DateTimeService::SECONDS_PER_WEEK;
        $directToCloudAgents = array_filter($agents, function (Agent $agent) {
            return $agent->isDirectToCloudAgent();
        });
        $activeDirectToCloudAgents = array_filter($directToCloudAgents, function (Agent $agent) use ($withinLastWeek) {
            return $agent->getLocal()->getLastCheckin() > $withinLastWeek;
        });
        $this->context->setAgents($agents);
        $this->context->setDirectToCloudAgents($directToCloudAgents);
        $this->context->setActiveDirectToCloudAgents($activeDirectToCloudAgents);

        // collect measurements
        $this->run();

        $this->logger->debug("MSC0005 Finished collecting statistics");
    }

    private function run()
    {
        foreach ($this->measurements as $measurement) {
            try {
                if ($measurement->enabled()) {
                    $this->logger->debug('MSC0020  - collecting ' . $measurement->description());
                    $measurement->collect($this->context);
                } else {
                    $this->logger->debug('MSC0021  - skipping ' . $measurement->description());
                }
            } catch (\Throwable $e) {
                $this->logger->warning('MSC0030 Measurement failed', ['exception' => $e]);
            }
        }
    }
}
