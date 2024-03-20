<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Virtualization\Libvirt\Libvirt;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class VirtualMachines extends Measurement
{
    /** @var KvmConnection */
    private $connection;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        KvmConnection $connection
    ) {
        parent::__construct($collector, $featureService, $logger);
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'virtual machines';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_RESTORE_VIRTUALIZATION);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        $states = [];

        foreach ($this->getDomains() as $domain) {
            $state = $this->getState($domain);
            $states[$state][] = $domain;
        }

        foreach (Libvirt::STATES as $state) {
            $domains = $states[$state] ?? [];

            $this->collector->measure(Metrics::STATISTIC_VIRTUAL_MACHINE_STATES, count($domains), [
                'state' => $state
            ]);
        }
    }

    /**
     * @return string[]
     */
    private function getDomains()
    {
        return $this->connection->getLibvirt()->hostGetDomains() ?: [];
    }

    /**
     * @param string $domain
     * @return string
     */
    private function getState(string $domain): string
    {
        return $this->connection->getLibvirt()->domainGetState($domain) ?: Libvirt::STATE_UNKNOWN;
    }
}
