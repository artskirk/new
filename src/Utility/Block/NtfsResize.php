<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\AbstractUtility;
use Exception;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Utility to interact with the `ntfsresize` command.
 *
 * @author Nathan Blair <nblair@datto.com>
 */
class NtfsResize extends AbstractUtility
{
    const BINARY_RESIZE = 'ntfsresize';
    const FLAG_INFO_ONLY = '-i';
    const FLAG_FORCE = '-f';
    const FLAG_BAD_SECTORS = '-b';
    const FLAG_SIZE = '-s';
    const FLAG_NO_ACTION = '-n';
    const CODE_CANNOT_RESIZE = 102;
    const MIN_SIZE = 'minSize';
    const CLUSTER_SIZE = 'clusterSize';
    const ORIGINAL_SIZE = 'originalSize';

    const REGEX_ORIGINAL_SIZE = '/Current volume size:[ ]([0-9]+)? bytes/';
    const REGEX_CLUSTER_SIZE = '/Cluster size [ ]+?: ([0-9]+)? bytes/';
    const REGEX_MIN_VOLUME_SIZE = '/You might resize at ([0-9]+)? bytes/';
    const REGEX_NEW_VOLUME_SIZE = '/New volume size [ ]+?: ([0-9]+)? bytes/';

    const TIMEOUT = 432000; // 5 days

    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Calculates the estimated minimum, original, and cluster size of the filesystem, in bytes,
     * by parsing ntfsresize output.
     *
     * @return int[]
     * @throws Exception
     */
    public function calculateEstimatedMinimumSize(string $path): array
    {
        // ntfsresize -i -f -b $path
        //
        // output:
        //        ntfsresize v2017.3.23AR.3 (libntfs-3g)
        //        Device name        : /dev/loop42p1
        //        NTFS volume version: 3.1
        //        Cluster size       : 4096 bytes
        //        Current volume size: 64317551104 bytes (64318 MB)
        //        Current device size: 64329105920 bytes (64330 MB)
        //        Checking filesystem consistency ...
        //        100.00 percent completed
        //        Accounting clusters ...
        //        Space in use       : 21654 MB (33.7%)
        //        Collecting resizing constraints ...
        //        You might resize at 21653872640 bytes or 21654 MB (freeing 42664 MB).
        //        Please make a test run using both the -n and -s options before real resizing!

        $output = $this->runNtfsResize([
            NtfsResize::FLAG_INFO_ONLY,
            NtfsResize::FLAG_FORCE,
            NtfsResize::FLAG_BAD_SECTORS,
            $path]);

        return [
            NtfsResize::ORIGINAL_SIZE => $this->parseForSize($output, NtfsResize::REGEX_ORIGINAL_SIZE),
            NtfsResize::MIN_SIZE => $this->parseForSize($output, NtfsResize::REGEX_MIN_VOLUME_SIZE),
            NtfsResize::CLUSTER_SIZE => $this->parseForSize($output, NtfsResize::REGEX_CLUSTER_SIZE)
        ];
    }

    /**
     * There's no known easy, reliable way of obtaining the *actual* minimum size for an NTFS snapshot. So, instead,
     * this method will call `ntfsresize`, starting with the theoretical minimum size, and incrementing the size 5%
     * per pass, until an approximate (safely within 5%) minimum size is found. If one is not found within the passes
     * through the loop (equating to 2x of the minimum size), then the process punts with the 2x value.
     *
     * @param int $recommendedMin the theoretical minimum resize value
     * @param int $clusterSize filesystem cluster size
     * @param int $originalSize filesystem original size
     * @param string $path the path for the filesystem
     * @param OutputInterface $output the symfony output interface
     * @return array a map containing the original, minimum, and cluster sizes
     * @throws Exception if there's an issue calculating the size
     */
    public function calculatePreciseMinimumSize(
        int $recommendedMin,
        int $clusterSize,
        int $originalSize,
        string $path,
        OutputInterface $output
    ): array {
        $maxSteps = 20;
        $currentMin = $recommendedMin;
        // Set min, add 5% at a time, align to cluster-size. Note this is going to do N-1 passes since we start at 1.
        for ($i = 1; $i < $maxSteps; $i++) {
            $currentMin = ceil(($recommendedMin * (1 + ($i / 20))) / $clusterSize) * $clusterSize;

            // This is a little chatty on stdout, but is needed by the NtfsResize.php class.
            $output->writeln("Checking attempt $i of $maxSteps for size $currentMin");

            // If larger than original filesystem size, cannot resize
            if ($currentMin > $originalSize) {
                throw new Exception($path . ' cannot be resized', NtfsResize::CODE_CANNOT_RESIZE);
            }

            try {
                $result = $this->attemptPreciseMinimumSize($currentMin, $path);

                // Made a successful safety run, can resize, currentMin is minimum size
                $output->writeln("Success on attempt $i of $maxSteps for size $currentMin");
                return $result;
            } catch (Exception $ex) {
                // This isn't really an error, just means the resize check didn't work.
                $this->logger->debug('NTR0001 Caught exception on running attemptPreciseMinimumSize', [
                    'ex' => $ex
                ]);
            }
        }

        $output->writeln("Unable to calculate a minimum size after $maxSteps attempts. Defaulting to $currentMin.");

        // If we don't get through the loop, return the default values.
        return [
            NtfsResize::ORIGINAL_SIZE => $originalSize,
            NtfsResize::MIN_SIZE => $currentMin,
            NtfsResize::CLUSTER_SIZE => $clusterSize
        ];
    }

    /**
     * Run a resize memory only safety run, verify moved data fits as expected
     *
     * @param int $targetSize
     * @param string $path
     * @return array
     * @throws Exception
     */
    private function attemptPreciseMinimumSize(int $targetSize, string $path): array
    {
        // ntfsresize -f -b -n -s $targetSize /dev/loop42p1
        //
        // output:
        //    ntfsresize v2017.3.23AR.3 (libntfs-3g)
        //    Device name        : /dev/loop42p1
        //    NTFS volume version: 3.1
        //    Cluster size       : 4096 bytes
        //    Current volume size: 64317551104 bytes (64318 MB)
        //    Current device size: 64329105920 bytes (64330 MB)
        //    New volume size    : 21999997440 bytes (22000 MB)
        //    Checking filesystem consistency ...
        //    100.00 percent completed
        //    Accounting clusters ...
        //    Space in use       : 21654 MB (33.7%)
        //    Collecting resizing constraints ...
        //    Needed relocations : 279305 (1145 MB)
        //    Schedule chkdsk for NTFS consistency check at Windows boot time ...
        //    Resetting $LogFile ... (this might take a while)
        //    Relocating needed data ...
        //    100.00 percent completed
        //    Updating $BadClust file ...
        //    Updating $Bitmap file ...
        //    Updating Boot record ...
        //    The read-only test run ended successfully.
        //

        $output = $this->runNtfsResize([
            NtfsResize::FLAG_FORCE,
            NtfsResize::FLAG_BAD_SECTORS,
            NtfsResize::FLAG_NO_ACTION,
            NtfsResize::FLAG_SIZE,
            $targetSize,
            $path]);

        return [
            NtfsResize::ORIGINAL_SIZE => $this->parseForSize($output, NtfsResize::REGEX_ORIGINAL_SIZE),
            NtfsResize::MIN_SIZE => $this->parseForSize($output, NtfsResize::REGEX_NEW_VOLUME_SIZE),
            NtfsResize::CLUSTER_SIZE => $this->parseForSize($output, NtfsResize::REGEX_CLUSTER_SIZE)
        ];
    }

    /**
     * Generic wrapper around ntfsresize.
     *
     * @param array $processArgs
     * @return string|null
     */
    private function runNtfsResize(array $processArgs): ?string
    {
        $process = $this->processFactory->get(
            [NtfsResize::BINARY_RESIZE, ...$processArgs],
            null,
            null,
            null,
            static::TIMEOUT
        );

        $process->mustRun();

        return $process->getOutput();
    }
}
