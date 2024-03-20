<?php

namespace Datto\Filesystem\Resize;

use Exception;

/**
 * Inspection
 * Resize functionality for EXT file systems.
 *
 * @author Mike Emeny <memeny@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ResizeExt extends ResizeFilesystem
{
    const RESIZE_ESTIMATE_FLAG = '-P';
    const RESIZE = 'resize2fs';
    const RESIZE_FORCE_FLAG = '-f';
    const RESIZE_PROGRESS_FLAG = '-p';
    const FSCK = 'e2fsck';
    const FSCK_FORCE_FLAG = '-f';
    const FSCK_NO_INTERACTIVE_FLAG = '-a';
    const FSCK_EXIT_GOOD = 0;
    const FSCK_EXIT_ERRORS_FIXED = 1;

    const STAGE_CALCMINSIZE = "Calculate minimum EXT size";

    const SNAPCTL_COMMAND = "asset:snapshot:ext:calcminsize";
    const RESIZE_FAIL = 200;
    const RESIZE_PARSE_FAIL = 210;
    const RESIZE_PROGRESS_ERROR = 220;
    const FSCK_FAIL = 230;

    /**
     * Calculates the minimum size by running resize2fs with estimate flag.
     *
     * @return int[]
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    public function calculateMinimumSize()
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        $minSize = 0;

        if ($this->canResize()) {
            try {
                $output = $this->runResize(true);
            } catch (Exception $e) {
                $this->cleanUpLoop($this->loopDevice);
                $this->logger->error('RSZ0002 Error running resize estimate', ['exception' => $e]);
                throw $e;
            }

            if (preg_match('/.+: ([0-9]+)/', $output, $minSize)) {
                $minSize = $minSize[1];
            } else {
                $this->logger->error('RSZ0003 Could not match resize estimate.');
                $this->cleanUpLoop($this->loopDevice);
                throw new Exception('Error parsing resize estimate.', self::RESIZE_PARSE_FAIL);
            }
        } else {
            $this->cleanUpLoop($this->loopDevice);
            throw new Exception($this->path . " cannot be resized", static::CODE_CANNOT_RESIZE);
        }

        $originalSize = $this->getPartitionSize();
        $blockSize = $this->getFilesystemBlockSize();

        $this->cleanUpLoop($this->loopDevice);
        return array(
            static::ORIGINAL_SIZE => $originalSize,
            static::MIN_SIZE => $minSize * $blockSize
        );
    }

    /**
     * Just return true as no resize2fs safeties
     *
     * @param int|null $targetSize
     * @return bool
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    public function resizeSafetyRun($targetSize = null)
    {
        return true;
    }

    /**
     * Resize to specific size, run in a screen, back-grounded
     * Returns whether or not the screen successfully started.
     *
     * @param int $targetSize desired filesystem size in bytes
     * @param int|null unused, inherited
     * @return bool
     */
    public function resizeToSize($targetSize, $blockSize = null)
    {
        $this->clearOutputFiles();

        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        //convert from bytes
        $targetSize = floor(
            $targetSize /
            $this->getFilesystemBlockSize()
        );

        $resizeCommand = array(
            self::RESIZE,
            self::RESIZE_FORCE_FLAG,
            self::RESIZE_PROGRESS_FLAG,
            escapeshellarg($this->loopDevice->getPathToPartition(static::LOOP_PARTITION_NUMBER)),
            escapeshellarg($targetSize),
            '2> ' . $this->stdErrFile,
            '1> ' . $this->stdOutFile
        );

        return $this->screen->runInBackgroundWithoutEscaping(implode(' ', $resizeCommand), $this->hash);
    }

    /**
     * Return array representing the progress of a resize process
     * Should return the following array:
     * ['running':bool, 'stage':string, 'percent':double, 'stdErr':string]
     *
     * @return ResizeProgress
     */
    protected function generateProgressObject()
    {
        if ($this->filesystem->exists($this->stdErrFile)) {
            $stdErr = $this->filesystem->fileGetContents($this->stdErrFile);
        } else {
            $stdErr = null;
        }

        if ($this->parseForNoOp($stdErr)) {
            $progress = array('stage' => 'Complete', 'percent' => 100);
        } else {
            $progress = $this->parseResizeOutput();
        }

        return new ResizeProgress(
            $this->screen->isScreenRunning($this->hash),
            $progress['stage'],
            $progress['percent'],
            $stdErr
        );
    }

    /**
     * Runs fsck to determine whether or not resize is possible
     *
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     *
     * @return bool
     */
    public function canResize()
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        try {
            $this->fsck();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Verify resize by making sure the same call yields "nothing to do"
     *
     * @param int $targetSize
     *
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     *
     * @return bool
     */
    public function verifyResize($targetSize)
    {
        if ($this->loopDevice === null) {
            return false;
        }

        try {
            $output = $this->runResize(false, $targetSize);
        } catch (Exception $e) {
            $this->logger->error('RSZ0011 Error verifying resize.', ['exception' => $e]);
            return false;
        }

        /*
         * Example output:
         * resize2fs 1.42 (29-Nov-2011)
         * The filesystem is already 1560686 blocks long.  Nothing to do!
         * The filesystem is already 1718894 (4k) blocks long
         */
        $matchString = "already $targetSize.+blocks long.";
        if (preg_match("/$matchString/", $output)) {
            return true;
        } else {
            $this->logger->warning('RSZ0015 Resize could not be verified');
            return false;
        }
    }

    /**
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     * @return int
     */
    private function getFilesystemBlockSize()
    {
        if (is_null($this->loopDevice)) {
            throw new Exception("Loop device does not exist");
        }

        // %s means print the block size in file-system mode
        // https://linux.die.net/man/1/stat
        $process = $this->processFactory
            ->get([
                "stat",
                "--file-system",
                $this->loopDevice->getPathToPartition(static::LOOP_PARTITION_NUMBER),
                "--format=%s"
            ]);

        try {
            $process->mustRun();
        } catch (Exception $e) {
            $this->logger->error('RSZ0010 Error executing stat', ['exception' => $e, 'command' => $process->getCommandLine()]);
            throw $e;
        }

        return trim($process->getOutput());
    }

    /**
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     * @return int
     */
    private function getPartitionSize()
    {
        if (is_null($this->loopDevice)) {
            throw new Exception("Loop device does not exist");
        }

        $process = $this->processFactory
            ->get([
                "blockdev",
                "--getsize64",
                $this->loopDevice->getPathToPartition(static::LOOP_PARTITION_NUMBER)
            ]);

        try {
            $process->mustRun();
        } catch (Exception $e) {
            $this->logger->error('RSZ0012 Blockdev execution failed', ['exception' => $e]);
            throw $e;
        }

        return trim($process->getOutput());
    }

    /**
     * @param bool $estimate
     * @param int|null $targetSize
     * @return string Process output
     */
    private function runResize($estimate = false, $targetSize = null)
    {
        $loop = $this->loopDevice->getPathToPartition(static::LOOP_PARTITION_NUMBER);
        $command = [self::RESIZE, $loop];
        if ($estimate) {
            $command[] = self::RESIZE_ESTIMATE_FLAG;
        }
        if ($targetSize !== null) {
            $command[] = $targetSize;
        }

        $process = $this->processFactory
            ->get($command)
            ->setTimeout(self::TIMEOUT);

        try {
            $process->mustRun();
            $this->logger->debug('RSZ0014 Resize output', ['output' => $process->getOutput()]);
            return $process->getOutput();
        } catch (Exception $e) {
            $this->logger->error('RSZ0015 Error executing resize', ['exception' => $e, 'errorOutput' => $process->getErrorOutput()]);
            throw new Exception('Error running resize', self::RESIZE_FAIL);
        }
    }

    /**
     * Run e2fsck against this loop device
     *
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    private function fsck()
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        $process = $this->processFactory
            ->get([
                self::FSCK,
                self::FSCK_FORCE_FLAG,
                self::FSCK_NO_INTERACTIVE_FLAG,
                $this->loopDevice->getPathToPartition(static::LOOP_PARTITION_NUMBER)
            ])
            ->setTimeout(self::TIMEOUT);

        $this->logger->info('RSZ0004 Checking filesystem for errors.');
        $process->run();
        $this->logger->info('RSZ0005 fsck output', ['output' => $process->getOutput()]);

        // fsck can exit with 1 and still be considered a successful run
        $allowedExitCodes = array(self::FSCK_EXIT_GOOD, self::FSCK_EXIT_ERRORS_FIXED);
        $fsckExitCode = $process->getExitCode();
        if (!in_array($fsckExitCode, $allowedExitCodes)) {
            $this->logger->info('RSZ0006 Filesystem contains errors.', ['errorOutput' => $process->getErrorOutput(), 'exitCode' => $fsckExitCode]);
            $this->logger->info('RSZ0007 Fsck returned errors.');
            throw new Exception("Unable to run fsck.", self::FSCK_FAIL);
        }
    }

    /**
     * Read resize2fs's output from a file to determine its progress
     *
     * @param string|null $file
     * @return array
     */
    private function parseResizeOutput($file = null)
    {
        $progressFile = $file ?: $this->stdOutFile;
        if (!$this->filesystem->exists($progressFile)) {
            $this->logger->warning('RSZ0008 Progress file does not exist', ['progressFile' => $progressFile]);
            return array('stage' => null, 'percent' => null);
        }

        $progress = $this->filesystem->fileGetContents($progressFile);
        $trimProgress = trim($progress);
        if (empty($trimProgress)) {
            $this->logger->warning('RSZ0009 Progress file is empty', ['progressFile' => $progressFile]);
            return array('stage' => null, 'percent' => null);
        }

        /*
         * Example output:
         * /dev/loop0p1 1847218
         * resize2fs 1.42.13 (17-May-2015)
         * Resizing the filesystem on /dev/loop0p1 to 1847218 (4k) blocks.
         * Begin pass 2 (max = 826001)
         * Relocating blocks             XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
         * Begin pass 3 (max = 152)
         * Scanning inode table          XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX-
         * Begin pass 4 (max = 22996)
         * Updating inode references     ----------------------------------------
         * The filesystem on /dev/loop0p1 is now 1847218 (4k) blocks long.
         */
        if ($this->parseForComplete($progress)) {
            return array('stage' => 'Complete', 'percent' => static::PERCENT_COMPLETE);
        }

        if ($this->hasUpdatingInodeReferencesStarted($progress)) {
            $pass3Progress = $this->parseForInodeReferences($progress);
            if ($pass3Progress == static::PERCENT_COMPLETE) {
                $stage = 'Complete';
            } else {
                $stage = 'Updating inode references';
            }
            return array('stage' => $stage, 'percent' => $pass3Progress);
        }

        if ($this->hasScanningInodeTableStarted($progress)) {
            $pass2Progress = $this->parseForInodeTable($progress);
            if ($pass2Progress != static::PERCENT_COMPLETE) {
                return array('stage' => 'Scanning inode table', 'percent' => $pass2Progress);
            }
        }

        if ($this->hasRelocatingBlocksStarted($progress)) {
            $pass1Progress = $this->parseForBlockRelocation($progress);
            if ($pass1Progress != static::PERCENT_COMPLETE) {
                return array('stage' => 'Relocating blocks', 'percent' => $pass1Progress);
            }
        }
        return array('stage' => null, 'percent' => null);
    }

    private function hasRelocatingBlocksStarted($progress): bool
    {
        return strpos($progress, 'Relocating blocks') !== false;
    }

    private function hasScanningInodeTableStarted($progress): bool
    {
        return strpos($progress, 'Scanning inode table') !== false;
    }

    private function hasUpdatingInodeReferencesStarted($progress): bool
    {
        return strpos($progress, 'Updating inode references') !== false;
    }

    private function parseForNoOp($stdErr): bool
    {
        return preg_match('/The filesystem is already/', $stdErr) &&
               preg_match('/Nothing to do!/', $stdErr);
    }

    private function parseForComplete($progress): bool
    {
        return preg_match('/is now/', $progress) &&
            preg_match('/blocks long/', $progress);
    }

    /**
     * @param string $progress
     * @return float|int
     */
    private function parseForBlockRelocation($progress)
    {
        if (preg_match('/Relocating blocks.+X{0,40}/', $progress, $match)) {
            return (substr_count($match[0], 'X') / 40) * 100;
        }
        return 0;
    }

    /**
     * @param string $progress
     * @return float|int
     */
    private function parseForInodeTable($progress)
    {
        if (preg_match('/Scanning inode table.+X{0,40}/', $progress, $match)) {
            return (substr_count($match[0], 'X') / 40) * 100;
        }
        return 0;
    }

    /**
     * @param string $progress
     * @return float|int
     */
    private function parseForInodeReferences($progress)
    {
        if (preg_match('/Updating inode references.+X{0,40}/', $progress, $match)) {
            return (substr_count($match[0], 'X') / 40) * 100;
        }
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function calculateMinimumSizeStart(): bool
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        try {
            $pathToPartition = $this->loopDevice->getPathToPartition(1);

            // snapctl asset:snapshot:ext:calcminsize --path /dev/loop44p1
            $command = [
                static::BINARY_SNAPCTL,
                static::SNAPCTL_COMMAND,
                static::FLAG_SNAPCTL_PATH,
                $pathToPartition,
                '2> '. $this->stdErrFile,
                '1> '. $this->stdOutFile
            ];

            return $this->screen->runInBackgroundWithoutEscaping(implode(' ', $command), $this->hash);
        } catch (Exception $e) {
            $this->logger->error('RSZ0002 Error starting ext filesystem minimum size calculation.', [
                "exception" => $e
            ]);
            throw new Exception('Error starting ext filesystem minimum size calculation', self::RESIZE_INFO_FAIL);
        } finally {
            $this->cleanUpLoop($this->loopDevice);
        }
    }

    /**
     * Report progress on a running async calcminsize by checking the output file that is accumulating the stdout
     * results of the `snapctl asset:snapshot:ext:calcminsize` command.
     *
     * Example output (success) of the snapctl command:
     *   root@backupDevice:~# snapctl asset:snapshot:ext:calcminsize --path /dev/loop44p1 2> /dev/null
     *   Successfully calculated minimum filesystem size
     *   Current volume size: 267386880 bytes
     *   Minimum volume size: 27074560 bytes
     *   Cluster size: 4096 bytes
     *
     * Example output (failure) of the snapctl command:
     *   /dev/loop1p1 cannot be resized
     */
    public function calculateMinimumSizeGenerateProgressObject(): CalcMinSizeProgress
    {
        $percentComplete = 0;
        $stage = ResizeExt::STAGE_CALCMINSIZE;
        $running = $this->screen->isScreenRunning($this->hash);

        $currentVolumeSize = 0;
        $minimumVolumeSize = 0;
        $clusterSize = 0;

        if ($this->filesystem->exists($this->stdOutFile)) {
            $output = $this->filesystem->fileGetContents($this->stdOutFile);

            if (strpos($output, "cannot be resized") !== false) {
                $percentComplete = ResizeFilesystem::PERCENT_COMPLETE;
                $stage = ResizeFilesystem::STAGE_FAILED;
            } elseif (strpos($output, 'Successfully calculated minimum filesystem size') !== false) {
                $percentComplete = ResizeFilesystem::PERCENT_COMPLETE;
                $stage = ResizeFilesystem::STAGE_COMPLETE;

                $currentVolumeSize = $this->parseForSize($output, ResizeFilesystem::REGEX_ORIGINAL_SIZE);
                $clusterSize = $this->parseForSize($output, ResizeFilesystem::REGEX_CLUSTER_SIZE);
                $minimumVolumeSize = $this->parseForSize($output, ResizeFilesystem::REGEX_MIN_VOLUME_SIZE);
            }
        }

        if ($this->filesystem->exists($this->stdErrFile)) {
            $stdErr = $this->filesystem->fileGetContents($this->stdErrFile);
        } else {
            $stdErr = null;
        }

        return new CalcMinSizeProgress(
            $running,
            $stage,
            $percentComplete,
            $stdErr,
            $currentVolumeSize,
            $minimumVolumeSize,
            $clusterSize,
        );
    }
}
