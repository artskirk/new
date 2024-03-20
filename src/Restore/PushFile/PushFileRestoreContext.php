<?php

namespace Datto\Restore\PushFile;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Datto\Config\AgentConfig;
use Datto\Mercury\TargetInfo;
use Datto\Restore\CloneSpec;
use Datto\Restore\Restore;

/**
 * Context for creating push file restores.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class PushFileRestoreContext
{
    private ?CloneSpec $cloneSpec;

    private Agent $agent;

    private int $snapshot;

    private Restore $restore;

    /** @var string[] */
    private array $pushFiles;

    private ?TargetInfo $targetInfo;

    private ?string $checksum;

    private ?string $zipPath;

    private ?int $lun;

    private DattoAgentApi $agentApi;

    private string $destination;

    private bool $keepBoth;

    private bool $restoreAcls;

    private string $assetKeyName;

    private string $hostOverride;

    private PushFileRestoreType $pushFileRestoreType;

    private ?int $size;

    private ?int $decompressedSize;

    /**
     * @param string[] $pushFiles
     */
    public function __construct(
        Agent $agent,
        Restore $restore,
        PushFileRestoreType $pushFileRestoreType,
        string $destination,
        bool $keepBoth,
        bool $restoreAcls,
        array $pushFiles,
        DattoAgentApi $agentApi
    ) {
        $this->agent = $agent;
        $this->snapshot = $restore->getPoint();
        $this->restore = $restore;
        $this->pushFileRestoreType = $pushFileRestoreType;
        $this->destination = $destination;
        $this->keepBoth = $keepBoth;
        $this->restoreAcls = $restoreAcls;
        $this->pushFiles = $pushFiles;
        $this->agentApi = $agentApi;
        $agentConfig = new AgentConfig($agent->getKeyName());
        $this->hostOverride = $agentConfig->get('hostOverride') ?? '';
        $this->assetKeyName = $agent->getKeyName();
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    public function getRestore(): Restore
    {
        return $this->restore;
    }

    /**
     * @return string[] Returns the list of files to restore
     */
    public function getPushFiles(): array
    {
        return $this->pushFiles;
    }

    public function getCloneSpec(): ?CloneSpec
    {
        return $this->cloneSpec;
    }

    public function setCloneSpec(CloneSpec $cloneSpec): void
    {
        $this->cloneSpec = $cloneSpec;
    }

    public function setTargetInfo(TargetInfo $targetInfo): void
    {
        $this->targetInfo = $targetInfo;
    }

    public function getTargetInfo(): ?TargetInfo
    {
        return $this->targetInfo;
    }

    public function setZipPath(string $zipPath): void
    {
        $this->zipPath = $zipPath;
    }

    public function getZipPath(): ?string
    {
        return $this->zipPath;
    }

    public function setLun(int $lun): void
    {
        $this->lun = $lun;
    }

    public function getLun(): ?int
    {
        return $this->lun;
    }

    public function setSize(int $size)
    {
        $this->size = $size;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setDecompressedSize(int $decompressedSize)
    {
        $this->decompressedSize = $decompressedSize;
    }

    public function getDecompressedSize(): int
    {
        return $this->decompressedSize;
    }

    public function getPushFileRestoreType(): PushFileRestoreType
    {
        return $this->pushFileRestoreType;
    }

    public function getAgentApi(): DattoAgentApi
    {
        return $this->agentApi;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): void
    {
        $this->checksum = $checksum;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getKeepBoth(): bool
    {
        return $this->keepBoth;
    }

    public function getRestoreAcls(): bool
    {
        return $this->restoreAcls;
    }

    public function getAssetKeyName(): string
    {
        return $this->assetKeyName;
    }

    public function getHostOverride(): string
    {
        return $this->hostOverride;
    }
}
