<?php
namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\LoggerAwareTrait;

use Datto\Screenshot\ScreenshotFileRepository;

/**
 * Remove old unneeded tif files from failed verifications
 *
 * @author Paul Anderson <panderson@datto.com>
 */
class RemoveVerificationTif implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $tifDirPath = ScreenshotFileRepository::SCREENSHOT_PATH;
        $tifFilesToExamine = $this->filesystem->glob($tifDirPath.'*.tif', GLOB_NOSORT);

        if (!is_array($tifFilesToExamine)) {
            return false;
        }

        $bytesFreed = 0;
        $filesRemoved = 0;
        foreach ($tifFilesToExamine as $tifFile) {
            $tifStat = $this->filesystem->stat($tifFile);
            if ($tifStat === false) {
                $this->logger->warning("RVF0000 failed to stat image file", ['file' => $tifFile]);
                continue;
            }

            if ($this->filesystem->unlinkIfExists($tifFile) === false) {
                $this->logger->warning("RVF0001 failed to remove image file", ['file' => $tifFile]);
                continue;
            }
            $bytesFreed = $bytesFreed + 512 * $tifStat['blocks'];
            $filesRemoved = $filesRemoved + 1;
        }

        if ($bytesFreed > 0) {
            $this->logger->info("RVF0002 removed excess tif image files", ['count' => $filesRemoved, 'free' => $bytesFreed]);
            return true;
        }

        return false;
    }
}
