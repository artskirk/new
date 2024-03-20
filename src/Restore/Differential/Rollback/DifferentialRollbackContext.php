<?php

namespace Datto\Restore\Differential\Rollback;

use Datto\Asset\Asset;
use Datto\Restore\CloneSpec;
use Datto\Restore\Restore;
use Datto\Utility\Security\SecretString;

/**
 * Context for creating Differential Rollback targets.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class DifferentialRollbackContext
{
    private Asset $asset;
    private int $snapshot;
    private CloneSpec $cloneSpec;
    private ?SecretString $passphrase = null;

    /** @var string[]|null */
    private ?array $lunPaths = null;

    private ?string $targetName = null;
    private ?Restore $restore = null;

    public function __construct(Asset $asset, int $snapshot, CloneSpec $cloneSpec, SecretString $passphrase = null)
    {
        $this->asset = $asset;
        $this->snapshot = $snapshot;
        $this->cloneSpec = $cloneSpec;
        $this->passphrase = $passphrase;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    public function getCloneSpec(): CloneSpec
    {
        return $this->cloneSpec;
    }

    public function getPassphrase(): ?SecretString
    {
        return $this->passphrase;
    }

    /**
     * @param string[] $lunPaths
     * @return void
     */
    public function setLunPaths(array $lunPaths): void
    {
        $this->lunPaths = $lunPaths;
    }

    /**
     * @return string[]|null
     */
    public function getLunPaths(): ?array
    {
        return $this->lunPaths;
    }

    public function setTargetName(string $targetName): void
    {
        $this->targetName = $targetName;
    }

    public function getTargetName(): ?string
    {
        return $this->targetName;
    }

    public function setRestore(Restore $restore): void
    {
        $this->restore = $restore;
    }

    public function getRestore(): ?Restore
    {
        return $this->restore;
    }
}
