<?php

namespace Datto\Filesystem\Resize;

use Datto\Block\LoopInfo;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Screen;
use Exception;

class ResizeXfs extends ResizeFilesystem
{
    private const DEFAULT_PARTITION = 1;

    private const SNAPCTL_COMMAND = 'asset:snapshot:xfs:calcminsize';

    private Blockdev $blockdev;

    public function __construct(
        string $agent,
        string $snapshot,
        string $extension,
        string $guid,
        LoopManager $loopManager,
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger,
        Screen $screen,
        Blockdev $blockdev,
        LoopInfo $loopDevice = null
    ) {
        parent::__construct(
            $agent,
            $snapshot,
            $extension,
            $guid,
            $loopManager,
            $processFactory,
            $filesystem,
            $logger,
            $loopDevice,
            $screen
        );

        $this->blockdev = $blockdev;
    }


    public function calculateMinimumSize(): int
    {
        if (is_null($this->loopDevice)) {
            $this->setupLoop();
        }

        /** @psalm-suppress PossiblyNullReference, PossiblyFalseArgument */
        return $this->blockdev->getSizeInBytes(
            $this->loopDevice->getPathToPartition(self::DEFAULT_PARTITION)
        );
    }

    public function resizeSafetyRun($targetSize): bool
    {
        return true;
    }

    public function resizeToSize($targetSize, $blockSize = null)
    {
        throw new \LogicException('The XFS file system does not support resizing');
    }

    public function generateProgressObject(): ResizeProgress
    {
        throw new \LogicException('The XFS file system does not support resizing');
    }

    public function calculateMinimumSizeStart(): bool
    {
        if (is_null($this->loopDevice)) {
            $this->setupLoop();
        }

        /** @psalm-suppress PossiblyNullReference */
        $path = $this->loopDevice->getPathToPartition(self::DEFAULT_PARTITION);

        return $this->screen->runInBackgroundWithoutEscaping(
            implode(
                ' ',
                [
                    ResizeFilesystem::BINARY_SNAPCTL,
                    self::SNAPCTL_COMMAND,
                    self::FLAG_SNAPCTL_PATH,
                    $path,
                    sprintf('2> %s', $this->stdErrFile),
                    sprintf('1> %s', $this->stdOutFile)
                ]
            ),
            $this->hash
        );
    }

    public function calculateMinimumSizeGenerateProgressObject(): CalcMinSizeProgress
    {
        try {
            if (!$this->filesystem->exists($this->stdOutFile)) {
                throw new Exception('Stdout file doesn\'t exists!');
            }

            $stdOut = $this->filesystem->fileGetContents($this->stdOutFile);

            if (!$stdOut) {
                throw new Exception('No output!');
            }

            return new CalcMinSizeProgress(
                false,
                ResizeFilesystem::STAGE_COMPLETE,
                ResizeFilesystem::PERCENT_COMPLETE,
                null,
                $this->parseForSize($stdOut, self::REGEX_ORIGINAL_SIZE),
                $this->parseForSize($stdOut, self::REGEX_MIN_VOLUME_SIZE),
                $this->parseForSize($stdOut, self::REGEX_CLUSTER_SIZE)
            );
        } catch (Exception $exception) {
            return new CalcMinSizeProgress(
                false,
                ResizeFilesystem::STAGE_FAILED,
                ResizeFilesystem::PERCENT_COMPLETE,
                $this->stdErrFile,
                0,
                0,
                0
            );
        }
    }
}
