<?php

namespace Datto\Filesystem\Resize;

use Datto\Block\LoopInfo;
use Exception;

/**
 * Resize functionality for NTFS file systems.
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ResizeNtfs extends ResizeFilesystem
{
    const BINARY_RESIZE = 'ntfsresize';
    const FLAG_INFO_ONLY = '-i';
    const FLAG_FORCE = '-f';
    const FLAG_BAD_SECTORS = '-b';
    const FLAG_SIZE = '-s';
    const FLAG_NO_ACTION = '-n';
    const STAGE_CALCMINSIZE = 'Calculate minimum NTFS size';
    const STAGE_CONSISTENCY_CHECK = 'Consistency Check';
    const STAGE_RELOCATING_DATA = 'Relocating Data';

    const SNAPCTL_COMMAND = 'asset:snapshot:ntfs:calcminsize';
    const FLAG_SNAPCTL_MODE = '--mode';
    const SNAPCTL_MODE_ESTIMATED = 'estimated';
    const SNAPCTL_MODE_PRECISE = 'precise';
    const FLAG_SNAPCTL_RECOMMENDED_MIN = '--recommended-min';
    const FLAG_SNAPCTL_CLUSTER_SIZE = '--cluster-size';
    const FLAG_SNAPCTL_ORIGINAL_SIZE = '--original-size';
    const REGEX_CHECKING_ATTEMPT = '/Checking attempt ([0-9]+)? of ([0-9]+)?/';

    /**
     * Calculates the minimum, original, and cluster size of the filesystem
     * by parsing ntfsresize output.
     *
     * @return int[]
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    public function calculateMinimumSize()
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        $processArgs = array(
            static::BINARY_RESIZE,
            static::FLAG_INFO_ONLY,
            static::FLAG_FORCE,
            static::FLAG_BAD_SECTORS,
            $this->loopDevice->getPathToPartition(1)
        );
        $process = $this->processFactory
            ->get($processArgs)
            ->setTimeout(self::TIMEOUT);

        try {
            $process->mustRun();
            $output = $process->getOutput();
            $originalSize = $this->parseForOriginalSize($output);
            $clusterSize = $this->parseForClusterSize($output);
            $recommendedMin = $this->parseForRecommendedMin($output);
        } catch (Exception $e) {
            $this->cleanUpLoop($this->loopDevice);
            $this->logger->error('RSZ0100 Error in ntfsresize info.', ['exception' => $e]);
            throw new Exception('Error in ntfsresize info', self::RESIZE_INFO_FAIL);
        }

        try {
            $currentMin = $this->attemptSizeReduction($recommendedMin, $clusterSize, $originalSize);
            $this->cleanUpLoop($this->loopDevice);
        } catch (Exception $e) {
            if ($this->loopDevice !== null) {
                $this->cleanUpLoop($this->loopDevice);
            }
            throw $e;
        }

        return array(
            static::ORIGINAL_SIZE => $originalSize,
            static::MIN_SIZE => $currentMin,
            static::CLUSTER_SIZE => $clusterSize
        );
    }

    /**
     * Run a resize memory only safety run, verify moved data fits as expected
     *
     * @param int $targetSize
     * @return bool
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    public function resizeSafetyRun($targetSize)
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        $processArgs = array(
            static::BINARY_RESIZE,
            static::FLAG_FORCE,
            static::FLAG_BAD_SECTORS,
            static::FLAG_NO_ACTION,
            static::FLAG_SIZE,
            $targetSize,
            $this->loopDevice->getPathToPartition(1)
        );

        $process = $this->processFactory
            ->get($processArgs)
            ->setTimeout(self::TIMEOUT);

        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Launch a resize in a screen to given size.
     * Returns whether or not the screen successfully started.
     *
     * @param int $targetSize
     * @param int|null $blockSize
     * @return bool
     */
    public function resizeToSize($targetSize, $blockSize = null)
    {
        $this->clearOutputFiles();

        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        $command = array(
            static::BINARY_RESIZE,
            static::FLAG_FORCE,
            static::FLAG_BAD_SECTORS,
            static::FLAG_SIZE,
            escapeshellarg($targetSize),
            escapeshellarg($this->loopDevice->getPathToPartition(1)),
            '2> '. $this->stdErrFile,
            '1> '. $this->stdOutFile
        );

        return $this->screen->runInBackgroundWithoutEscaping(implode(' ', $command), $this->hash);
    }

    /**
     * Report progress on a running resize
     *
     * @return ResizeProgress
     */
    protected function generateProgressObject()
    {
        $percent = 0.00;
        $dataMoving = false;
        $stage = static::STAGE_CONSISTENCY_CHECK;
        $running = $this->screen->isScreenRunning($this->hash);

        /*
         *   Example output:
         *   ntfsresize v2015.3.14AR.1 (libntfs-3g)
         *   Device name        : /dev/loop6p1
         *   NTFS volume version: 3.1
         *   Cluster size       : 4096 bytes
         *   Current volume size: 128322630144 bytes (128323 MB)
         *   Current device size: 128334184960 bytes (128335 MB)
         *   Checking filesystem consistency ...
         *   100.00 percent completed
         *   Accounting clusters ...
         *   Space in use       : 59719 MB (46.5%)
         *   Collecting resizing constraints ...
         *   You might resize at 59718795264 bytes or 59719 MB (freeing 68604 MB).
         *   Please make a test run using both the -n and -s options before real resizing!
         */
        if ($this->filesystem->exists($this->stdOutFile)) {
            $fp = $this->filesystem->open($this->stdOutFile, 'r');
            while ($line = $this->filesystem->getLine($fp)) {
                if (strpos($line, "Nothing to do: NTFS volume size is already OK.") !== false ||
                    strpos($line, "Successfully resized NTFS on device") !== false) {
                    $percent = static::PERCENT_COMPLETE;
                    $stage = static::STAGE_COMPLETE;
                    break;
                }
                if (strpos($line, "Relocating needed data ...") !== false) {
                    $dataMoving = true;
                    $stage = static::STAGE_RELOCATING_DATA;
                    break;
                }
            }
            if ($dataMoving) {
                // Only read last 50 bytes from end of file (progress gets huge)
                $progressLines = trim($this->filesystem->readAt($fp, -50, SEEK_END));
                $progressArray = explode("\r", $progressLines);
                $progressEnd = trim(array_pop($progressArray));
                // If data started moving and last line of progress log is not 'percent completed' then resize is complete
                if (strpos($progressEnd, "percent completed") !== false) {
                    $percentArray = explode(" ", $progressEnd);
                    $percent = doubleval(trim(array_shift($percentArray)));
                } else {
                    $percent = 100.00;
                }
            }

            $this->filesystem->close($fp);
        }

        if ($this->filesystem->exists($this->stdErrFile)) {
            $stdErr = $this->filesystem->fileGetContents($this->stdErrFile);
        } else {
            $stdErr = null;
        }

        return new ResizeProgress(
            $running,
            $stage,
            $percent,
            $stdErr
        );
    }

    /**
     * @param int $recommendedMin
     * @param int $clusterSize
     * @param int $originalSize
     * @return int
     * @deprecated BCDR-25568
     */
    private function attemptSizeReduction($recommendedMin, $clusterSize, $originalSize)
    {
        $currentMin = 0;
        // Set min, add 5% at a time, align to cluster-size
        for ($i = 1; $i < 20; $i++) {
            $currentMin = ceil(($recommendedMin * (1 + ($i / 20))) / $clusterSize) * $clusterSize;

            // If larger than original filesystem size, cannot resize
            if ($currentMin > $originalSize) {
                throw new Exception($this->path . " cannot be resized", static::CODE_CANNOT_RESIZE);
            }

            // Made a successful safety run, can resize, currentMin is minimum size
            if ($this->resizeSafetyRun($currentMin)) {
                break;
            }
        }

        return $currentMin;
    }

    /**
     * Parse out original volume size
     *
     * Searching for this line:
     * Current volume size: 128322630144 bytes (128323 MB)
     * @param string $output
     * @return double
     * @deprecated BCDR-25568
     */
    private function parseForOriginalSize($output)
    {
        if (preg_match("/Current volume size\:[ ]([0-9]+)? bytes/", $output, $matchOrigSize)) {
            if (isset($matchOrigSize[1])) {
                return doubleval(trim($matchOrigSize[1]));
            }
        }

        throw new Exception("Failed to parse original NTFS volume size");
    }

    /**
     * Parse out the Cluster size, as we will want to align to resize attempts to cluster size
     *
     * Searching for this line:
     * Cluster size       : 4096 bytes
     * @param string $output
     * @return double
     * @deprecated BCDR-25568
     */
    private function parseForClusterSize($output)
    {
        if (preg_match("/Cluster size [ ]+?: ([0-9]+)? bytes/", $output, $matchClusterSize)) {
            if (isset($matchClusterSize[1])) {
                return doubleval(trim($matchClusterSize[1]));
            }
        }

        throw new Exception("Failed to parse cluster NTFS volume cluster size");
    }

    /**
     * Parse out the minimum size recommended for shrinking
     *
     * Searching for this line:
     * You might resize at 59718795264 bytes or 59719 MB (freeing 68604 MB).
     * @param string $output
     * @return double
     * @deprecated BCDR-25568
     */
    private function parseForRecommendedMin($output)
    {
        if (preg_match("/You might resize at ([0-9]+)? bytes/", $output, $matchSize)) {
            if (isset($matchSize[1])) {
                return doubleval(trim($matchSize[1]));
            }
        }

        throw new Exception("Failed to parse NTFS volume minimum size");
    }

    /**
     * @inheritdoc
     */
    public function calculateMinimumSizeStart(): bool
    {
        if ($this->loopDevice === null) {
            $this->setupLoop();
        }

        /* so calcminsize actually does two things, I've split them into part 1 and part 2.
        after a little testing, it became clear that the slow part is the part 2 part.
        So we call part 1 and wait which is quick, and we'll fire off part 2 in
        the background screen. */

        try {
            $pathToPartition = $this->loopDevice->getPathToPartition(1);

            // part 1
            [$originalSize, $clusterSize, $recommendedMin] = $this->calculateEstimatedMinimumSize($pathToPartition);

            // part 2
            $command = [
                ResizeFilesystem::BINARY_SNAPCTL,
                ResizeNtfs::SNAPCTL_COMMAND,
                ResizeNtfs::FLAG_SNAPCTL_MODE,
                ResizeNtfs::SNAPCTL_MODE_PRECISE,
                ResizeNtfs::FLAG_SNAPCTL_RECOMMENDED_MIN,
                $recommendedMin,
                ResizeNtfs::FLAG_SNAPCTL_CLUSTER_SIZE,
                $clusterSize,
                ResizeNtfs::FLAG_SNAPCTL_ORIGINAL_SIZE,
                $originalSize,
                ResizeNtfs::FLAG_SNAPCTL_PATH,
                $pathToPartition,
                '2> '. $this->stdErrFile,
                '1> '. $this->stdOutFile
            ];

            return $this->screen->runInBackgroundWithoutEscaping(implode(' ', $command), $this->hash);
        } catch (Exception $e) {
            $this->logger->error('RSZ0016 Error starting ntfs minimum size calculation.', ['exception' => $e]);
            throw new Exception('Error in starting ntfs minimum size calculation', self::RESIZE_INFO_FAIL);
        } finally {
            $this->cleanUpLoop($this->loopDevice);
        }
    }

    /**
     * Report progress on a running async calcminsize by checking the output file that is accumulating the stdout
     * results of the `snapctl asset:snapshot:ntfs:calcminsize` command.
     *
     * Example output (success):
     *   root@backupDevice:~# snapctl asset:snapshot:ntfs:calcminsize --mode precise --path /dev/loop42p1 \
     *           --cluster-size 4096 --original-size 64317551104 --recommended-min 21059436544 2> /dev/null
     *   Checking attempt 1 of 20 for size 15478689792
     *   Checking attempt 2 of 20 for size 16215769088
     *   -- snip --
     *   Checking attempt 8 of 20 for size 20638248960
     *   Checking attempt 9 of 20 for size 21375328256
     *   Success on attempt 9 of 20 for size 21375328256
     *   Current volume size: 64317551104 bytes
     *   Minimum volume size: 21375324672 bytes
     *   Cluster size: 4096 bytes
     *
     * Example output (failure):
     *   Checking attempt 1 of 20 for size 6633725952
     *   Checking attempt 2 of 20 for size 6949617664
     *   -- snip --
     *   Checking attempt 18 of 20 for size 12003880960
     *   Checking attempt 19 of 20 for size 12319772672
     *   Unable to calculate a minimum size after 20 attempts. Defaulting to 12319772672.
     *   Current volume size: 64317551104 bytes
     *   Minimum volume size: 12319772672 bytes
     *   Cluster size: 4096 bytes
     */
    public function calculateMinimumSizeGenerateProgressObject(): CalcMinSizeProgress
    {
        $percentComplete = 0;
        $stage = ResizeNtfs::STAGE_CALCMINSIZE;
        $running = $this->screen->isScreenRunning($this->hash);

        $currentVolumeSize = 0;
        $minimumVolumeSize = 0;
        $clusterSize = 0;

        if ($this->filesystem->exists($this->stdOutFile)) {
            $output = $this->filesystem->fileGetContents($this->stdOutFile);

            if (strpos($output, 'Unable to calculate a minimum size after') !== false) {
                $percentComplete = ResizeFilesystem::PERCENT_COMPLETE;
                $stage = ResizeFilesystem::STAGE_FAILED;
            } elseif (strpos($output, 'Success on attempt') !== false) {
                $percentComplete = ResizeFilesystem::PERCENT_COMPLETE;
                $stage = ResizeFilesystem::STAGE_COMPLETE;

                $currentVolumeSize = $this->parseForSize($output, ResizeFilesystem::REGEX_ORIGINAL_SIZE);
                $clusterSize = $this->parseForSize($output, ResizeFilesystem::REGEX_CLUSTER_SIZE);
                $minimumVolumeSize = $this->parseForSize($output, ResizeFilesystem::REGEX_MIN_VOLUME_SIZE);
            } elseif (($checkCount = preg_match_all(ResizeNtfs::REGEX_CHECKING_ATTEMPT, $output, $matches, PREG_PATTERN_ORDER)) !== false) {
                $percentComplete = intval(100.0 * floatval($matches[1][$checkCount - 1]) / floatval($matches[2][$checkCount - 1]));
            } else {
                $stage = ResizeFilesystem::STAGE_FAILED;
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
            $clusterSize
        );
    }

    /**
     * run ntfsresize to get stats on the filesystem to be used by the sync and async versions of calcreminsize
     *
     * @return double[]
     * @throws Exception
     */
    private function calculateEstimatedMinimumSize(string $path): array
    {
        $processArgs = [
            ResizeFilesystem::BINARY_SNAPCTL,
            ResizeNtfs::SNAPCTL_COMMAND,
            ResizeNtfs::FLAG_SNAPCTL_MODE,
            ResizeNtfs::SNAPCTL_MODE_ESTIMATED,
            ResizeFilesystem::FLAG_SNAPCTL_PATH,
            $path
        ];
        $process = $this->processFactory
            ->get($processArgs)
            ->setTimeout(ResizeFilesystem::TIMEOUT);

        try {
            $process->mustRun();
            $output = $process->getOutput();

            $originalSize = $this->parseForSize($output, ResizeFilesystem::REGEX_ORIGINAL_SIZE);
            $clusterSize = $this->parseForSize($output, ResizeFilesystem::REGEX_CLUSTER_SIZE);
            $recommendedMin = $this->parseForSize($output, ResizeFilesystem::REGEX_MIN_VOLUME_SIZE);
        } catch (Exception $e) {
            $this->logger->error('RSZ0000 Error during ntfs calculateEstimatedMinimumSize.', ['exception' => $e]);
            throw new Exception('Error in calculateEstimatedMinimumSize', ResizeFilesystem::RESIZE_INFO_FAIL);
        }
        return [$originalSize, $clusterSize, $recommendedMin];
    }
}
