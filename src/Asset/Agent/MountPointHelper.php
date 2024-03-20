<?php

namespace Datto\Asset\Agent;

use Datto\Block\LoopInfo;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\System\MountManager;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\DeviceLoggerInterface;

class MountPointHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    /** @var MountManager */
    private $mountManager;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        Filesystem $filesystem,
        DeviceLoggerInterface $logger = null,
        ProcessFactory $processFactory = null,
        MountManager $mountManager = null,
        Sleep $sleep = null
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->mountManager = $mountManager ?: new MountManager();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Tries $tries (once by default) times to unmount a target $target
     *
     * @param string $target
     * @param int $tries
     * @return bool true if successfully unmounted
     */
    public function unmountSingle($target, $tries = 1)
    {
        $target = $this->sanitizeMountpointForWindows($target);
        $process = $this->processFactory->get(['umount', $target]);

        for ($i = 0; $i < $tries; $i++) {
            $process->run();

            if ($process->isSuccessful()) {
                break;
            }

            $blockDev = $target;
            if ($this->filesystem->isBlockDevice($blockDev) === false) {
                $blockDev = $this->mountManager->getMountPointDevice($blockDev);
            }

            if ($blockDev) {
                // wait for sync
                $this->processFactory->get(['blockdev', '--flushbufs', $blockDev])->run();
            }

            // wait for 0.1 second
            $this->sleep->msleep(100);
        }

        return $process->isSuccessful();
    }

    /**
     * @return MountedVolume[]
     */
    public function mountAll(Volumes $volumes, array $loopMap, string $destDir, bool $readOnly = true): array
    {
        $mountedVolumes = [];

        foreach ($volumes->getArrayCopy() as $volume) {
            // It's possible that a volume could exist in the voltab/agentInfo data but be excluded, so we need to check
            // that a loop was actually created for it.
            if (array_key_exists($volume->getGuid(), $loopMap)) {
                $device = $loopMap[$volume->getGuid()]->getPath();
                if ($this->filesystem->exists("{$device}p1")) {
                    $loopVolume = "{$device}p1";
                } else {
                    if ($this->filesystem->exists("{$device}1")) {
                        $loopVolume = "{$device}1";
                    } else {
                        $this->logger->warning('MPH0110 Partition is not present, not mounting', ['device' => $device, 'pathFormat1' => "{$device}p1", 'pathFormat2' => "{$device}1"]);
                        // we can't mount something that doesn't exist
                        continue;
                    }
                }

                $sanitizedMountPoint = $this->sanitizeMountpointForWindows($volume->getMountpoint());

                $volumeMountPath = "$destDir/$sanitizedMountPoint";

                if (!$this->filesystem->isDir($volumeMountPath)) {
                    $this->filesystem->mkdir($volumeMountPath, true, 0775);
                }

                $this->logger->debug(
                    'MPH0021 Mounting volume',
                    [
                        'volume' => $volume->toArray(),
                        'mountingAt' => $volumeMountPath
                    ]
                );

                $mountOptions = MountManager::MOUNT_OPTION_FORCE;
                if ($readOnly) {
                    $mountOptions |= MountManager::MOUNT_OPTION_READ_ONLY;
                }

                if ($volume->getFilesystem() === MountManager::FILESYSTEM_TYPE_XFS) {
                    $mountOptions |= MountManager::MOUNT_OPTION_NO_UUID;
                }

                $mountResult = $this->mountManager->mountDevice($loopVolume, $volumeMountPath, $mountOptions);

                // if we failed run fsck and try mounting again
                if ($mountResult->mountFailed()) {
                    $this->logger->error(
                        'MPH0100 Failed to mount volume',
                        [
                            'loopVolume' => "{$volume->getFilesystem()}:{$loopVolume}",
                            'mountPoint' => $volumeMountPath,
                            'output' => $mountResult->getMountOutput()
                        ]
                    );
                } else {
                    $mountedVolumes[] = new MountedVolume($volume, $volumeMountPath);
                }
            }
        }
        $volumeCount = count($volumes);
        $successCount = count($mountedVolumes);

        if ($successCount === $volumeCount) {
            $this->logger->debug('MPH0022 Mounted all volumes successfully', ['success' => $successCount, 'total' => $volumeCount]);
        } elseif ($successCount > 0) {
            $this->logger->warning('MPH0023 Mounted volumes successfully', ['success' => $successCount, 'total' => $volumeCount]);
        } else {
            $this->logger->error('MPH0024 All volumes failed to mount');
            throw new Exception('All volumes failed to mount');
        }

        return $mountedVolumes;
    }

    /**
     * Sanitizes colon and backslashes out of the mountpoint - these characters cause issues when they are
     * in windows mountpoints.
     *
     * @param string $data The mountpoint string to sanitize
     * @return string The sanitized mountpoint string
     */
    private function sanitizeMountpointForWindows($data)
    {
        $mountpoint = preg_replace("/^([A-Z]):\\\\$/", "$1", $data, 1);
        return $mountpoint;
    }
}
