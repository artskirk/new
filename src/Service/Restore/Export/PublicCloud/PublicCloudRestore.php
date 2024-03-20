<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Config\JsonConfigRecord;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Config Record Interface to hold all the running Public Cloud Restores to be used by DeviceState.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudRestore extends JsonConfigRecord implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const STATE_UPLOADING = 'uploading';
    const STATE_DONE = 'upload-completed';
    const STATE_FAILED = 'upload-failed';

    /** @var array<string, string> */
    private $restores = [];

    /**
     * @inheritDoc
     */
    public function getKeyName(): string
    {
        return 'publicCloudRestore';
    }

    public function addRestore(string $asset, int $snapshot)
    {
        $arrayKey = $this->getRestoreKey($asset, $snapshot);
        if (array_key_exists($arrayKey, $this->restores)) {
            $this->logger->info(
                "PUB0014 arrayKey already exists in list of public cloud restores, resetting state",
                ['arrayKey' => $arrayKey, 'currentRestores' => $this->restores]
            );
        }

        $this->restores[$arrayKey] = self::STATE_UPLOADING;
    }

    public function removeRestore(string $asset, int $snapshot)
    {
        if (!array_key_exists($this->getRestoreKey($asset, $snapshot), $this->restores)) {
            return;
        }

        unset(
            $this->restores[$this->getRestoreKey(
                $asset,
                $snapshot
            )]
        );
    }

    public function setRestoreState(string $asset, int $snapshot, string $state)
    {
        $this->assertExists($asset, $snapshot);
        $this->restores[$this->getRestoreKey($asset, $snapshot)] = $state;
    }

    public function getRestoreState(string $asset, int $snapshot): string
    {
        $this->assertExists($asset, $snapshot);
        return $this->restores[$this->getRestoreKey($asset, $snapshot)];
    }

    private function getRestoreKey(string $asset, int $snapshot)
    {
        return "{$asset}_{$snapshot}";
    }

    private function assertExists(string $asset, int $snapshot)
    {
        $arrayKey = $this->getRestoreKey($asset, $snapshot);
        if (!array_key_exists($arrayKey, $this->restores)) {
            throw new Exception("$arrayKey doesn't exist in list of public cloud restores");
        }
    }

    protected function load(array $vals)
    {
        $this->restores = $vals;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->restores;
    }
}
