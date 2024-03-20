<?php

namespace Datto\Filesystem\Resize;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\Screen;
use Datto\Log\LoggerFactory;
use Datto\Block\LoopInfo;
use Datto\Block\LoopManager;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Determine whether or not a filesystem can be resized, calculates the minimum sizes,
 * and gets progress during the resize process.
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class ResizeFilesystem
{
    const POOL_PATH = '/homePool/';
    const CONFIG_PATH = '/datto/config/keys/';
    const CODE_SETUP_FAIL = 101;
    const CODE_CANNOT_RESIZE = 102;
    const RESIZE_INFO_FAIL = 300;
    const MIN_SIZE = 'minSize';
    const CLUSTER_SIZE = 'clusterSize';
    const ORIGINAL_SIZE = 'originalSize';
    const PERCENT_COMPLETE = 100;
    const LOOP_PARTITION_NUMBER = 1;
    const TIMEOUT = 432000; // 5 days
    const BINARY_SNAPCTL = "snapctl";
    const STAGE_COMPLETE = "Complete";
    const STAGE_FAILED = "Failed";
    const REGEX_ORIGINAL_SIZE = '/Current volume size:[ ]?([0-9]+)? bytes/';
    const REGEX_CLUSTER_SIZE = '/Cluster size:[ ]+?([0-9]+)? bytes/';
    const REGEX_MIN_VOLUME_SIZE = '/Minimum volume size:[ ]+?([0-9]+)? bytes/';
    const FLAG_SNAPCTL_PATH = "--path";

    /** @var string path to image file */
    protected $path = "";

    /** @var string hash to identify resizing logs and screens */
    protected $hash = "";

    /** @var string */
    protected $loopPartition = "p1";

    protected ProcessFactory $processFactory;

    /** @var LoopManager */
    protected $loopManager;

    /** @var Filesystem */
    protected $filesystem;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var Screen */
    protected $screen;

    /** @var string */
    protected $stdOutFile;

    /** @var string */
    protected $stdErrFile;

    /** @var string */
    protected $cleanedFile;

    /**
     * @var LoopInfo|null
     */
    protected $loopDevice;

    public function __construct(
        $agent,
        $snapshot,
        $extension,
        $guid,
        LoopManager $loopManager = null,
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        DeviceLoggerInterface $logger = null,
        LoopInfo $loopDevice = null,
        Screen $screen = null
    ) {
        $this->path = self::POOL_PATH . $agent . '-' . $snapshot . '-' . $extension . '/' . $guid . '.datto';
        $this->hash = $agent . '-resize-' . $snapshot . '-' . $guid;
        $this->loopManager = $loopManager ?: new LoopManager();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->loopDevice = $loopDevice ?: null;
        $this->screen = $screen ?: new Screen($this->logger);
        $this->stdOutFile = self::CONFIG_PATH . $this->hash . '.log';
        $this->stdErrFile = self::CONFIG_PATH . $this->hash . '.stdErr';
        $this->cleanedFile = self::CONFIG_PATH . $this->hash . '.cleaned';
    }

    /**
     * Create a loop device backed by $file
     *
     * @param string $file
     */
    public function setupLoop($file = null)
    {
        if (!isset($file)) {
            $file = $this->path;
        }

        $loops = $this->loopManager->getLoopsOnFile($file);
        if ($loops) {
            $this->logger->debug('RSZ1001 File is already looped.', ['filename' => $file]);
            $this->loopDevice = $loops[0];
            $this->setCleanStatus(true);
        } else {
            try {
                $this->loopDevice = $this->loopManager->create($file, LoopManager::LOOP_CREATE_PART_SCAN);
            } catch (Exception $exception) {
                $this->logger->error('RSZ1002 Failed to setup loop device.', ['exception' => $exception]);
                throw new Exception('Failed to setup loop device.', self::CODE_SETUP_FAIL, $exception);
            }
        }
    }

    /**
     * Kill a running resize screen
     *
     * @return bool
     */
    public function stopResize()
    {
        return $this->screen->killScreen($this->hash);
    }

    /**
     * Gets resize progress
     *
     * @return ResizeProgress
     */
    public function getResizeProgress()
    {
        $progress = $this->generateProgressObject();

        $needsCleanup = !$progress->isRunning() && $this->needsCleanup();

        if ($needsCleanup) {
            try {
                $this->cleanUpLoop();
                $this->setCleanStatus(true);
            } catch (Exception $e) {
                // We don't really care if this fails
            }
        }

        return $progress;
    }

    /**
     * Destroy the loop device
     *
     * @param LoopInfo $loop
     * @return bool
     */
    protected function cleanUpLoop(LoopInfo $loop = null)
    {
        // Don't tear down if we didn't create the loop.
        if (!$this->needsCleanup()) {
            return true;
        }

        if ($loop === null) {
            if (isset($this->loopDevice)) {
                $loop = $this->loopDevice;
            } else {
                $loopInfos = $this->loopManager->getLoopsOnFile($this->path);
                if (!empty($loopInfos)) {
                    foreach ($loopInfos as $loopInfo) {
                        $this->loopManager->destroy($loopInfo);
                    }
                    return true;
                } else {
                    $this->logger->info('RSZ1003 No loop to destroy');
                    return false;
                }
            }
        }
        if ($this->filesystem->exists($this->stdOutFile)) {
            $this->filesystem->unlink($this->stdOutFile);
        }
        $this->loopManager->destroy($loop);
        $this->loopDevice = null;
        return true;
    }

    /**
     * Set file to log stderr to
     *
     * @param $filePath
     * @return bool
     */
    protected function setStdErrFile($filePath)
    {
        if ($this->filesystem->exists($filePath)) {
            $this->stdErrFile = $filePath;
            return true;
        }
        return false;
    }

    /**
     * Remove any stale progress/error files.
     */
    protected function clearOutputFiles()
    {
        if ($this->filesystem->exists($this->cleanedFile)) {
            $this->filesystem->unlink($this->cleanedFile);
        }

        if ($this->filesystem->exists($this->stdOutFile)) {
            $this->filesystem->unlink($this->stdOutFile);
        }

        if ($this->filesystem->exists($this->stdErrFile)) {
            $this->filesystem->unlink($this->stdErrFile);
        }
    }

    /**
     * Set whether or not we can consider the loops already clean.
     *
     * @param bool $isAlreadyClean
     */
    protected function setCleanStatus(bool $isAlreadyClean)
    {
        $this->filesystem->filePutContents($this->cleanedFile, $isAlreadyClean ? '1' : '0');
    }

    /**
     * Determine whether we need to clean up loops.
     *
     * @return bool
     */
    protected function needsCleanup(): bool
    {
        $alreadyClean = '0';
        if ($this->filesystem->exists($this->cleanedFile)) {
            $alreadyClean = trim($this->filesystem->fileGetContents($this->cleanedFile));
        }
        return (int)$alreadyClean === 0;
    }

    /**
     * Parse a size value from the output of the command, using a regex. The regex *MUST* contain one or more groups, or
     * this method will fail. Only the first group is considered for the match.
     *
     * @param string $output the command output
     * @param string $regex the regular expression used to parse the command output
     * @return int the size value
     * @throws Exception if the value can't be parsed with the regular expression
     */
    protected function parseForSize(string $output, string $regex): int
    {
        if (preg_match($regex, $output, $size)) {
            if (isset($size[1])) {
                return intval(trim($size[1]));
            }
        }

        $this->logger->error("RFS0001 Unable to parse calculateMinimumSize output.", ['output' => $output, 'regex' => $regex]);

        throw new Exception("Failed to parse calculateMinimumSize output '$output' with '$regex'");
    }

    /**
     * Calculates the minimum size of the filesystem, and if applicable
     * the original and cluster size.
     *
     * @return int[]
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    abstract public function calculateMinimumSize();

    /**
     * Runs the resize binary in safety mode to ensure resize
     * is possible.
     *
     * @param int $targetSize
     * @return bool
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    abstract public function resizeSafetyRun($targetSize);

    /**
     * Resizes filesystem to specified size.
     *
     * @param int $targetSize
     * @param int|null $blockSize
     * @return bool
     */
    abstract public function resizeToSize($targetSize, $blockSize = null);

    /**
     * Report progress on a running resize. Subclasses should implement
     * resize status specific logic for their respective filesystems
     *
     * @return ResizeProgress
     */
    abstract protected function generateProgressObject();

    /**
     * starts an async calcminsize operation
     *
     * @return bool
     * @throws Exception if there is a problem starting the process
     */
    abstract public function calculateMinimumSizeStart(): bool;

    /**
     * Report progress on a running async calcminsize.
     *
     * @return CalcMinSizeProgress
     */
    abstract public function calculateMinimumSizeGenerateProgressObject(): CalcMinSizeProgress;

    /**
     * Kill a running resize screen
     *
     * @return bool
     */
    public function calculateMinimumSizeStop(): bool
    {
        return $this->screen->killScreen($this->hash);
    }
}
