<?php

namespace Datto\Virtualization;

use Datto\Asset\Agent\OperatingSystem;
use Datto\Common\Utility\Filesystem;
use Datto\Util\OsFamily;
use InvalidArgumentException;

/**
 * Makes sure generated Libvirt XML config results in a bootable VM on ESX
 * based on OS info. In OS2, most VM configuration files are initially created
 * speficially for VBox virutalization, therefore this class is supposed to be
 * used right before making a final call to create a VM as the generated config
 * might still have some vbox-specific settings in there that need to be
 * "sanitized" to work in ESX.
 *
 * @author Dawid Zamirski <dzamirsk@datto.com>
 */
class EsxVmdkPrepTask
{
    /** @var Filesystem */
    private $filesystem = null;

    /** @var OperatingSystem */
    private $operatingSystem;

    /**
     * @param OperatingSystem $operatingSystem
     * @param Filesystem $filesystem
     */
    public function __construct(
        OperatingSystem $operatingSystem,
        Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
        $this->operatingSystem = $operatingSystem;
    }

    /**
     * Sets ddb.adapterType property on *.vmdk meta files that do not have it.
     *
     * This is required for storage vMotion to work - no needed for normal
     * virtualization restore.
     *
     * @param string $storageDir
     * @return void
     */
    public function setVmdkAdapterType(string $storageDir)
    {
        if (false === $this->filesystem->exists($storageDir)) {
            throw new InvalidArgumentException(sprintf(
                'The provided directory does not exist: %s',
                $storageDir
            ));
        }

        $adapterType = 'lsilogic';
        if ($this->operatingSystem->getOsFamily() === OsFamily::WINDOWS()
            && $this->isLegacyWindows($this->operatingSystem->getVersion() ?? '')) {
            $adapterType = 'buslogic';
        }

        $vmdks = $this->filesystem->glob($storageDir . '/*.vmdk');

        foreach ($vmdks as $vmdk) {
            $file = $this->filesystem->fileGetContents($vmdk);
            $lines = explode(PHP_EOL, $file);
            $hasAdapterType = false;

            foreach ($lines as $line) {
                if (strpos($line, 'ddb.adapterType') !== false) {
                    $hasAdapterType = true;
                    break;
                }
            }

            // get rid of last empty element due to EOL at end of file.
            $last = end($lines);
            if ($last !== false && empty($last)) {
                unset($lines[key($lines)]);
            }

            if ($hasAdapterType === false) {
                $data = sprintf('ddb.adapterType = "%s"', $adapterType) . PHP_EOL;
                $lines[] = $data;
                $this->filesystem->filePutContents(
                    $vmdk,
                    implode(PHP_EOL, $lines),
                    LOCK_EX
                );
            }
        }
    }

    /**
     * Checks if passed windows version is one that needs legacy VM devices.
     *
     * @param string $winVer
     *
     * @return bool
     */
    private function isLegacyWindows(string $winVer)
    {
        // Windows 2003 or older.
        return version_compare($winVer, '5.3', '<');
    }
}
