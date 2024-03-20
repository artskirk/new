<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\System\Storage\Encrypted\EncryptedStorageService;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class EncryptedStorage extends Measurement
{
    /** @var EncryptedStorageService */
    private $encryptedStorageService;

    /**
     * @inheritDoc
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        EncryptedStorageService $encryptedStorageService
    ) {
        parent::__construct($collector, $featureService, $logger);
        $this->encryptedStorageService = $encryptedStorageService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'encrypted storage information';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_STORAGE_ENCRYPTION);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        $encryptedDisks = $this->encryptedStorageService->getEncryptedDisks();
        $hasGeneratedKey = $this->encryptedStorageService->testGeneratedKeyMany($encryptedDisks);

        // Gather
        $results = [];
        foreach ($encryptedDisks as $encryptedDisk) {
            $results[$encryptedDisk->getName()]['missing']
                = $hasGeneratedKey[$encryptedDisk->getName()] ? 0 : 1;
            $results[$encryptedDisk->getName()]['locked']
                = $this->encryptedStorageService->hasDiskBeenUnlocked($encryptedDisk) ? 0 : 1;
        }

        // Collect (deferred to measure all-at-once instead of on-demand, because reading disk information can be slow)
        foreach ($results as $name => $result) {
            $tags = ['disk_name' => $name];

            $this->collector->measure(
                Metrics::STATISTICS_STORAGE_DISK_MISSING_GENERATED_KEY,
                $result['missing'],
                $tags
            );
            $this->collector->measure(
                Metrics::STATISTICS_STORAGE_DISK_LOCKED,
                $result['locked'],
                $tags
            );
        }
    }
}
