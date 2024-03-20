<?php

namespace Datto\Filesystem\Resize;

use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Screen;
use Exception;

/**
 * Class ResizeFactory
 * Creates resize objects based on filesystem type
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Mike Emeny <menemy@datto.com>
 */
class ResizeFactory
{
    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    /** @var LoopManager */
    private $loopManager;

    private ProcessFactory $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Screen */
    private $screen;

    public function __construct(
        AgentSnapshotService $agentSnapshotService,
        LoopManager $loopManager,
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger,
        Screen $screen
    ) {
        $this->agentSnapshotService = $agentSnapshotService;
        $this->loopManager = $loopManager;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->screen = $screen;
    }

    /**
     * Return resize object for filesystem for given guid
     *
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @param string $guid
     * @return ResizeFilesystem
     */
    public function getResizeObject($agent, $snapshot, $extension, $guid)
    {
        $filesystemType = null;
        $agentSnapshot = $this->agentSnapshotService->get($agent, $snapshot);
        $volumes = $agentSnapshot->getVolumes()->getArrayCopy();
        foreach ($volumes as $volume) {
            if ($volume->getGuid() === $guid) {
                $filesystemType = $volume->getFilesystem();
                break;
            }
        }

        if ($filesystemType === null) {
            throw new Exception("Unable to determine filesystem type of $guid");
        }

        $filesystemType = strtolower($filesystemType);

        switch ($filesystemType) {
            // All ext filesystems behave the same
            case 'ext2':
            case 'ext3':
            case 'ext4':
                return new ResizeExt(
                    $agent,
                    $snapshot,
                    $extension,
                    $guid,
                    $this->loopManager,
                    $this->processFactory,
                    $this->filesystem,
                    $this->logger,
                    null,
                    $this->screen
                );
            case 'ntfs':
                return new ResizeNtfs(
                    $agent,
                    $snapshot,
                    $extension,
                    $guid,
                    $this->loopManager,
                    $this->processFactory,
                    $this->filesystem,
                    $this->logger,
                    null,
                    $this->screen
                );
            case 'xfs':
                return new ResizeXfs(
                    $agent,
                    $snapshot,
                    $extension,
                    $guid,
                    $this->loopManager,
                    $this->processFactory,
                    $this->filesystem,
                    $this->logger,
                    $this->screen,
                    new Blockdev($this->processFactory),
                    null
                );
            default:
                throw new Exception("This filesystem [$filesystemType] does not support resizing");
        }
    }
}
