<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\LocalConfig;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Remove the obsolete local key files.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class RemoveObsoleteLocalKeys implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    const KEYS = [
        ['keyName' => 'autoRefresh'],
        ['keyName' => 'connectivity'],
        ['keyName' => 'ownCloudDomainAlias'],
        ['keyName' => 'speedLimit', 'deleteKeyIfFeaturePresent' => FEATURESERVICE::FEATURE_LOCAL_BANDWIDTH_SCHEDULING]
    ];

    private LocalConfig $localConfig;
    private FeatureService $featureService;

    public function __construct(LocalConfig $localConfig, FeatureService $featureService)
    {
        $this->localConfig = $localConfig;
        $this->featureService = $featureService;
    }

    public function run(): bool
    {
        $changesOccurred = false;

        foreach (self::KEYS as $key) {
            $changesOccurred = $this->check($key) || $changesOccurred;
        }

        return $changesOccurred;
    }

    private function check(array $key): bool
    {
        if (array_key_exists('deleteKeyIfFeaturePresent', $key)) {
            if ($this->featureService->isSupported($key['deleteKeyIfFeaturePresent'])) {
                return $this->removeKey($key['keyName']);
            }
        } else {
            return $this->removeKey($key['keyName']);
        }

        return false;
    }

    private function removeKey(string $keyName): bool
    {
        if ($this->localConfig->has($keyName)) {
            $this->logger->warning("CFG0008 clearing local key", ['keyPath' => "/datto/config/local/".$keyName]);
            $this->localConfig->clear($keyName);
            return true;
        }
        return false;
    }
}
