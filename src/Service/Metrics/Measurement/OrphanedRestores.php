<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Feature\FeatureService;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\MetricsContext;

/**
 * Measurement of orphaned restores.
 */
class OrphanedRestores extends Measurement
{
    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'orphaned restores';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_RESTORE);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        foreach ($context->getOrphanedRestores() as $orphanedRestore) {
            $asset = $orphanedRestore->getAssetObject();
            $this->collector->increment(Metrics::STATISTIC_RESTORE_ORPHAN, [
                'type' => $orphanedRestore->getSuffix(),
                'is_replicated' => $asset->getOriginDevice()->isReplicated(),
            ]);
        }
    }
}
