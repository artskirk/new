<?php

namespace Datto\Restore;

use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Connection\ConnectionInterface;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\ImageExport\BootType;
use Datto\Restore\HIR\Builder as HirBuilder;
use Datto\Restore\HIR\MachineType as HirMachineType;
use Datto\Log\DeviceLoggerInterface;

/**
 * Factory for AgentHir
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentHirFactory
{
    private ?HirBuilder $hirBuilder;
    private ?ProcessFactory $processFactory;
    private ?Filesystem $filesystem;
    private ?LoopManager $loopManager;
    private ?DeviceConfig $deviceConfig;
    private ?DeviceLoggerInterface $logger;
    private ?LinkedVmdkMaker $linkedVmdkMaker;

    public function __construct(
        HirBuilder $hirBuilder = null,
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        LoopManager $loopManager = null,
        DeviceConfig $deviceConfig = null,
        DeviceLoggerInterface $logger = null,
        LinkedVmdkMaker $linkedVmdkMaker = null
    ) {
        $this->hirBuilder = $hirBuilder;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->loopManager = $loopManager;
        $this->deviceConfig = $deviceConfig;
        $this->logger = $logger;
        $this->linkedVmdkMaker = $linkedVmdkMaker;
    }

    public function create(
        string $assetKey,
        string $datasetDir,
        ConnectionInterface $connection = null,
        HirMachineType $machineType = null,
        BootType $bootType = null,
        bool $skipHirOnPendingReboot = false
    ): AgentHir {
        return new AgentHir(
            $assetKey,
            $datasetDir,
            $connection,
            $machineType,
            $bootType,
            $this->hirBuilder,
            $this->processFactory,
            $this->filesystem,
            $this->loopManager,
            $this->deviceConfig,
            $this->logger,
            $this->linkedVmdkMaker,
            $skipHirOnPendingReboot
        );
    }
}
