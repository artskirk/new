<?php

namespace Datto\Config\RepairTask;

use Datto\Config\LocalConfig;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLogger;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\AssetCloneManager;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Remove leftover clones and files from the removed feature "diskless restore"
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class RemoveDisklessRestoreLeftovers implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var LocalConfig */
    private $localConfig;

    public function __construct(AssetCloneManager $assetCloneManager, LocalConfig $localConfig)
    {
        $this->assetCloneManager = $assetCloneManager;
        $this->localConfig = $localConfig;
    }

    public function run(): bool
    {
        $modified = false;
        $cloneSpecs = $this->assetCloneManager->getAllClones();

        foreach ($cloneSpecs as $cloneSpec) {
            if ($cloneSpec->getSuffix() === 'diskless') {
                try {
                    $this->assetCloneManager->destroyClone($cloneSpec);
                    $modified = true;
                } catch (Throwable $e) {
                    $this->logger->info(
                        'DRL0001 Error tearing down diskless restore clone',
                        ['cloneDatasetName' => $cloneSpec->getTargetDatasetName(), 'exception' => $e]
                    );
                }
            }

            $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);
        }

        if ($this->localConfig->has('disklessProvisionedMedia')) {
            $this->localConfig->clear('disklessProvisionedMedia');
            $modified = true;
        }

        if ($this->localConfig->has('disklessRecoveryPoints')) {
            $this->localConfig->clear('disklessRecoveryPoints');
            $modified = true;
        }

        return $modified;
    }
}
