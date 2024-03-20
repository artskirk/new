<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Utility\Systemd\Systemctl;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class SystemdServiceStatus extends Measurement
{
    const STATUS_OK = 0;
    const STATUS_NOT_RUNNING = 1;
    const STATUS_FAILED = 2;

    const SERVICES_OF_INTEREST = [
        'dtcserver.service',
        'filebeat.service',
        'apache2.service',
        'datto-websockify.service',
        'libvirtd.service',
        'mercuryftp.service',
        'php7.4-fpm.service',
        'datto-asset-remount.service'
    ];

    /** @var Systemctl */
    private $systemctl;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        Systemctl $systemctl
    ) {
        parent::__construct($collector, $featureService, $logger);

        $this->systemctl = $systemctl;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'systemd service information';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        foreach (self::SERVICES_OF_INTEREST as $service) {
            if ($this->systemctl->isFailed($service)) {
                $status = self::STATUS_FAILED;
            } elseif (!$this->systemctl->isActive($service)) {
                $status = self::STATUS_NOT_RUNNING;
            } else {
                $status = self::STATUS_OK;
            }

            $this->collector->measure(Metrics::STATISTIC_DTC_SYSTEMD_SERVICES, $status, [
                'service_name' => $service
            ]);
        }
    }
}
