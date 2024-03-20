<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Connection\Libvirt\KvmConnection;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Restore\RestoreType;
use Datto\Metrics\Metrics;
use Datto\Feature\FeatureService;

/**
 * Measurement of active restores.
 */
class ActiveRestores extends Measurement
{
    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'active restores';
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
        foreach ($context->getActiveRestores() as $restore) {
            $suffix = $restore->getSuffix();
            $options = $restore->getOptions();
            $asset = $restore->getAssetObject();
            $tags = [];

            if ($suffix === RestoreType::ACTIVE_VIRT) {
                $connectionName = $options['connectionName'];
                $type = RestoreType::ACTIVE_VIRT . '-' . ($connectionName === KvmConnection::CONNECTION_NAME ? 'local' : 'hypervisor');
                $tags = [
                    'vmPoweredOn' => $options['vmPoweredOn'] ? 'on' : 'off'
                ];
            } elseif ($suffix === RestoreType::EXPORT) {
                $networkExport = $options['network-export'] ?? true;
                $type = RestoreType::EXPORT . '-' . ($networkExport ? 'network' : 'usb');
            } else {
                $type = $restore->getSuffix();
            }

            $this->collector->increment(Metrics::STATISTIC_RESTORE_ACTIVE, array_merge($tags, [
                'type' => $type,
                'is_replicated' => $asset->getOriginDevice()->isReplicated(),
            ]));
        }
    }
}
