<?php
namespace Datto\Restore;

use Datto\Asset\AssetType;
use Datto\Block\LoopInfo;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Connection\ConnectionInterface;
use Datto\Connection\ConnectionType;
use Datto\ImageExport\BootType;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Datto\Restore\HIR\Builder as HirBuilder;
use Datto\Restore\HIR\MachineType as HirMachineType;
use Datto\Restore\HIR\PurposeType as HirPurposeType;
use Datto\Restore\HIR\Result as HirResult;
use Datto\Common\Utility\Filesystem;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

/**
 * The glue for running HIR on SIRIS.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class AgentHir
{
    /*
     * Specifies how large boot.datto volumes should be
     */
    const BOOTVOL_TRUNCATE_SIZE = '200M';

    /*
     * Commands must finish within this period of time (for sanity)
     */
    const PROCESS_TIMEOUT_SECONDS = 120;

    /*
     * Name of the HIR failure flag (file)
     */
    const HIR_FAILURE_FLAG = 'HIRFailed';

    /*
     * Allows for the (hopefully temporary) disablement of HIR
     */
    const DEBUG_DISABLE_HIR_FLAG = 'debugDisableHIR';

    /*
     * Name of the dataset (verbose) log file
     */
    const HIR_DATASET_LOG_FILE = 'hir.log';

    /*
     * If the os_version field is missing this regex will
     * be used to determine whether or not the OS is 'legacy'.
     */
    const LEGACY_WINDOWS_REGEX =
        '/.*(Windows\s+2000|Windows\s+XP)|(Server.*2000|2000.*Server)|(Server.*2003|2003.*Server).*/i';

    /** @var string */
    private $assetKey;

    /** @var string */
    private $datasetDir;

    /** @var ConnectionInterface */
    private $connection;

    /** @var HirBuilder */
    private $hirBuilder;

    private ProcessFactory $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var LoopManager */
    private $loopManager;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var array|null */
    private $agentInfo;

    /** @var string|null */
    private $osName;

    /** @var string|null */
    private $assetType;

    /** @var HirMachineType|null */
    private $machineType;

    /** @var BootType */
    private $bootType;

    /** @var LinkedVmdkMaker */
    private $linkedVmdkMaker;

    /** @var bool */
    private $skipHirOnPendingReboot;

    public function __construct(
        $assetKey,
        $datasetDir,
        ConnectionInterface $connection = null,
        HirMachineType $machineType = null,
        BootType $bootType = null,
        HirBuilder $hirBuilder = null,
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        LoopManager $loopManager = null,
        DeviceConfig $deviceConfig = null,
        DeviceLoggerInterface $logger = null,
        LinkedVmdkMaker $linkedVmdkMaker = null,
        bool $skipHirOnPendingReboot = false
    ) {
        $this->assetKey = $assetKey;
        $this->datasetDir = $datasetDir;
        $this->connection = $connection;
        $this->agentInfo = null;
        $this->osName = null;
        $this->assetType = null;
        $this->machineType = $machineType;
        $this->bootType = $bootType ?: BootType::BIOS();
        $this->hirBuilder = $hirBuilder ?: new HirBuilder();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->loopManager = $loopManager ?: new LoopManager();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($assetKey);
        $this->linkedVmdkMaker = $linkedVmdkMaker ?: new LinkedVmdkMaker($this->filesystem);
        $this->skipHirOnPendingReboot = $skipHirOnPendingReboot;
    }

    /**
     * Executes HIR on the directory passed to the constructor.
     * After this method returns the images should be bootable.
     *
     * @return HirResult
     */
    public function execute()
    {
        // Temporarily add handler for logging to cloned/HIR directory. Will be removed before function returns.
        $this->setUpHirLogger();

        $this->logger->info('HIR5015 Launching HIR');

        if ($this->deviceConfig->has(self::DEBUG_DISABLE_HIR_FLAG)) {
            $this->logger->warning('HIR5000 HIR is temporarily disabled for debugging purposes, exiting.');

            // Remove the temporary handler for logging to the clone/HIR directory.
            $this->cleanUpHirLogger();

            return new HirResult($successful = false);
        }

        // Preflight tasks
        try {
            $this->logger->debug('HIR5001 Starting HIR preflight.');
            $this->preflight();
            $this->logger->debug('HIR5002 Completed HIR preflight.');
        } catch (Exception $ex) {
            $this->logger->error('HIR5003 An error occurred during HIR preflight.', ['exception' => $ex]);
            $this->createHirFailedFlag($ex);

            // Remove the temporary handler for logging to the clone/HIR directory.
            $this->cleanUpHirLogger();

            return new HirResult($successful = false, $ex);
        }

        $machineType = $this->machineType ?: $this->determineHirMachineType();

        // Execute HIR
        try {
            $this->logger->debug('HIR5004 Starting HIR process.');
            $this->hirBuilder
                ->setLogger($this->logger)
                ->setMachineType($machineType)
                ->addVolumesFromSirisDataset($this->agentInfo, $this->datasetDir)
                ->setPurposeType(HirPurposeType::STANDARD());
            $hir = $this->hirBuilder->build();
            $result = $hir->execute($this->skipHirOnPendingReboot);

            if ($result->failed()) {
                $this->createHirFailedFlag($result->getException());
            } elseif ($result->getRebootPending() && $this->skipHirOnPendingReboot) {
                $this->logger->info('HIR5021 rebootPending is true - HIR was not run.');
            } else {
                $this->logger->info('HIR5016 HIR completed successfully.');
            }
        } catch (Exception $ex) {
            $this->logger->error('HIR5005 Failed to create HIR object.', ['exception' => $ex]);
            $this->createHirFailedFlag($ex);

            // Remove the temporary handler for logging to the clone/HIR directory.
            $this->cleanUpHirLogger();

            return new HirResult($successful = false, $ex);
        }

        // Remove the temporary handler for logging to the clone/HIR directory.
        $this->cleanUpHirLogger();

        return $result;
    }

    /**
     * Return whether a boot.datto volume needs to be created for this agent.
     * boot.datto files are used for all non-legacy versions of windows
     *
     * @return bool
     */
    public function needsBootVolume(): bool
    {
        $this->loadAgentInfo();

        $bootVolumePath = $this->datasetDir . '/boot.datto';
        if ($this->filesystem->exists($bootVolumePath)) {
            return false;
        }

        // One of these two fields should exist...
        $this->osName = isset($this->agentInfo['os_name']) ? $this->agentInfo['os_name'] : $this->agentInfo['os'];
        $this->assetType = isset($this->agentInfo['type']) ? $this->agentInfo['type'] : null;

        $isWindows = stristr($this->osName, 'windows') !== false
            || $this->assetType === AssetType::WINDOWS_AGENT
            || $this->assetType === AssetType::AGENTLESS_WINDOWS;

        return $isWindows && !preg_match(self::LEGACY_WINDOWS_REGEX, $this->osName);
    }

    /**
     * Pre-HIR tasks
     */
    private function preflight()
    {
        // Remove any files that may have been created by a previous run
        $failureFlag = $this->datasetDir . '/' . self::HIR_FAILURE_FLAG;
        if ($this->filesystem->exists($failureFlag)) {
            $this->filesystem->unlink($failureFlag);
        }
        $bootVolumePath = $this->datasetDir . '/boot.datto';
        if ($this->filesystem->exists($bootVolumePath)) {
            $this->filesystem->unlink($bootVolumePath);
        }
        $vmdkGlobPattern = $this->datasetDir . '/*.vmdk';
        foreach ($this->filesystem->glob($vmdkGlobPattern) as $vmdkFile) {
            $this->filesystem->unlink($vmdkFile);
        }

        $this->loadAgentInfo();

        if ($this->bootType === BootType::AUTO()) {
            foreach ($this->agentInfo['volumes'] as $label => $volume) {
                $this->bootType = (array_key_exists('realPartScheme', $volume)
                    && ($volume['realPartScheme'] === 'GPT')) ? BootType::UEFI() : BootType::BIOS();
                if ($volume['OSVolume'] === 1) {
                    break;
                }
            }
            $this->logger->debug('HIR5010 Determined Boot Type as ' . $this->bootType . '.');
        }

        // Create a boot.datto volume if needed.
        if ($this->needsBootVolume()) {
            $this->logger->debug('HIR5006 Creating boot volume.');
            $this->createBootVolume();
        } else {
            $this->logger->debug('HIR5009 Skipping boot volume creation (unsupported for this OS).');
        }

        // Create VMDKs for each volume
        $this->logger->debug('HIR5007 Creating VMDKs.');
        $this->createVmdks();
    }

    private function loadAgentInfo()
    {
        if ($this->agentInfo) {
            return;
        }
        // Locate the correct agentInfo file
        $agentInfoPath = $this->datasetDir . '/' . $this->assetKey . '.agentInfo';
        if (!$this->filesystem->exists($agentInfoPath)) {
            // Our first check will fail if the agent was renamed.
            // We must fallback to glob because we don't know the original name.
            $agentInfoGlob = $this->filesystem->glob($this->datasetDir . '/*.agentInfo');
            if (count($agentInfoGlob) === 1) {
                $agentInfoPath = $agentInfoGlob[0];
            } else {
                throw new Exception('Failed to locate agentInfo.');
            }
        }
        $this->agentInfo = unserialize($this->filesystem->fileGetContents($agentInfoPath), ['allowed_classes' => false]);
    }

    /**
     * Creates a boot.datto volume containing 1 partition,
     * to be used as the System Reserved Partition.
     * https://technet.microsoft.com/en-us/library/gg441289.aspx
     *
     */
    private function createBootVolume()
    {
        $bootVolumePath = $this->datasetDir . '/boot.datto';

        // Create an empty sparse image
        $this->processFactory
            ->get(['truncate', '--size=' . self::BOOTVOL_TRUNCATE_SIZE, $bootVolumePath])
            ->setTimeout(self::PROCESS_TIMEOUT_SECONDS)
            ->mustRun();

        // Create SRP
        if ($this->bootType === BootType::UEFI()) {
            $this->createUefiBootPartition($bootVolumePath);
        } else {
            $this->createBiosBootPartition($bootVolumePath);
        }

        try {
            $loop = $this->loopManager->create($bootVolumePath, LoopManager::LOOP_CREATE_PART_SCAN);

            if ($this->bootType === BootType::UEFI()) {
                $this->createUefiFileSystem($loop);
            } else {
                $this->createBiosFileSystem($loop);
            }
        } catch (Exception $ex) {
            // Last ditch cleanup before we exit
            if (isset($loop)) {
                $this->loopManager->destroy($loop);
            }
            throw $ex;
        }

        $this->filesystem->chmod($bootVolumePath, 0666);

        // Cleanup the loop device
        $this->loopManager->destroy($loop);
    }

    /**
     * Creates a single NTFS partition labeled msdos on a volume spanning the entire volume.
     * It is set as the primary partition with the boot flag set.
     *
     * @param string $bootVolumePath The file path to the boot.datto volume
     */
    private function createBiosBootPartition(string $bootVolumePath)
    {
        // NTFS-3G's formatter explicitly requires a block device
        $this->processFactory
            ->get(['parted', $bootVolumePath, '--script', 'mklabel msdos', 'mkpart primary ntfs 0% 100%', 'set 1 boot on'])
            ->setTimeout(self::PROCESS_TIMEOUT_SECONDS)
            ->mustRun();
    }

    /**
     * Creates a single FAT32 partition of UEFI type on the passed boot.datto volume path
     *
     * @param string $bootVolumePath The file path to the boot.datto volume
     */
    private function createUefiBootPartition(string $bootVolumePath)
    {
        $this->processFactory
            ->get([
                'sgdisk',
                '--clear',
                '--new=1::-0', // create 1 new partition using the entire volume
                '--typecode=1:ef00', // mark the partition type as efi
                $bootVolumePath
            ])
            ->setTimeout(self::PROCESS_TIMEOUT_SECONDS)
            ->mustRun();
    }

    /**
     * Creates a NTFS filesystem for a BIOS MBR on a loop device partition
     *
     * @param LoopInfo $loop The info for the loop device to create the filesystem on
     * @param int $part The partition number to create the file system on, defaults to the first
     */
    private function createBiosFileSystem(LoopInfo $loop, int $part = 1)
    {
        $this->processFactory
            ->get(['mkfs.ntfs', '--fast', $loop->getPathToPartition($part)])
            ->setTimeout(self::PROCESS_TIMEOUT_SECONDS)
            ->mustRun();
    }

    /**
     * Creates a vFAT32 filesystem for a EFI partition on a loop device partition
     *
     * @param LoopInfo $loop The info for the loop device to create the filesystem on
     * @param int $part The partition number to create the file system on, defaults to the first
     */
    private function createUefiFileSystem(LoopInfo $loop, int $part = 1)
    {
        $this->processFactory
            ->get(['mkfs.vfat', '-F', '32', $loop->getPathToPartition($part)])
            ->setTimeout(self::PROCESS_TIMEOUT_SECONDS)
            ->mustRun();
    }

    /**
     * Creates a .vmdk for all datto images inside of the directory
     */
    private function createVmdks()
    {
        $noletterIndex = 0;
        $dattoGlobPattern = $this->datasetDir . '/*.datto';
        foreach ($this->filesystem->glob($dattoGlobPattern) as $index => $imagePath) {
            // Find an appropriate name for the VMDK.
            $vmdkName = null;
            $imageGuid = pathinfo($imagePath, PATHINFO_FILENAME);
            foreach ($this->agentInfo['volumes'] as $volume) {
                // A lot of issets, I know.
                // You can't trust agentInfo files.
                if (isset($volume['guid']) && $volume['guid'] === $imageGuid) {
                    if (isset($volume['blockDevice']) && stripos($volume['blockDevice'], '/dev/') === 0) {
                        // Linux
                        $vmdkName = basename($volume['blockDevice']);
                    } elseif (isset($volume['mountpoints'])) {
                        // Windows
                        $vmdkName = rtrim(trim($volume['mountpoints']), ':\\');
                    } else {
                        // Fallback to 'noletter' vmdks (unlikely edge case)
                        $vmdkName = null;
                    }
                    break;
                }
            }
            if (empty($vmdkName)) {
                $vmdkName = ($imageGuid === 'boot') ? 'boot' : 'noletter' . $noletterIndex++;
            }

            $this->linkedVmdkMaker->make($imagePath, $vmdkName . '.vmdk');
        }
    }

    /**
     * Logs the exception to 'HIRFailed' inside of the dataset.
     *
     * @param Exception $ex
     */
    private function createHirFailedFlag(Exception $ex)
    {
        $this->filesystem->filePutContents(
            $this->datasetDir . '/HIRFailed',
            json_encode(array(
                'time'    => time(),
                'message' => $ex->getMessage(),
                'file'    => $ex->getFile(),
                'line'    => $ex->getLine(),
            ))
        );
    }

    /**
     * Set up a StreamHandler for logging to the clone directory.
     */
    private function setUpHirLogger()
    {
        if ($this->logger instanceof MonologLogger) {
            $this->logger->pushHandler(new StreamHandler($this->datasetDir . '/' . self::HIR_DATASET_LOG_FILE));
        }
    }

    /**
     * Remove the StreamHandler for logging to the clone directory.
     */
    private function cleanUpHirLogger()
    {
        // Assuming we just need to only pop the most recently added handler
        if ($this->logger instanceof MonologLogger) {
            $this->logger->popHandler();
        }
    }

    /**
     * Determine the appropriate HirMachineType to use based on the ConnectionType
     *
     * @return HirMachineType
     */
    private function determineHirMachineType()
    {
        if ($this->connection !== null && $this->connection->getType() === ConnectionType::LIBVIRT_HV()) {
            return HirMachineType::HYPER_V();
        } else {
            return HirMachineType::VIRTUAL();
        }
    }
}
