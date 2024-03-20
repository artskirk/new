<?php

namespace Datto\Restore;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Class that deal with creating a linked vmdk based on a passed raw disk image file.
 * The created vmdk file will be in the same directory as the given disk file.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class LinkedVmdkMaker
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * @param string $imagePath
     * @param string $vmdkName
     */
    public function make(string $imagePath, string $vmdkName)
    {
        $imageName = basename($imagePath);
        $imageDir = dirname($imagePath);
        $vmdkPath = $this->filesystem->join($imageDir, $vmdkName);

        // If image file does not exist, just bail, possibly a locked encrypted image
        if (!$this->filesystem->exists($imagePath)) {
            return;
        }
        $imageSizeBytes = $this->filesystem->getSize($imagePath);

        // These magic numbers were taken from the original implementation.
        // Most of the time don't really matter.
        $cylinders = round($imageSizeBytes / (512 * 255 * 63));
        $sz = floor($imageSizeBytes / 512);
        $cid = sprintf('%08x', mt_rand(0, 0xfffffffe));
        $uuid = md5(microtime() . $cid);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            substr($uuid, 12, 4),
            substr($uuid, 16, 4),
            substr($uuid, 20, 12)
        );
        $vmdkContents =
            "# Disk DescriptorFile\n" .
            "version=1\n" .
            "CID=$cid\n" .
            "parentCID=ffffffff\n" .
            "createType=\"vmfs\"\n" .
            "\n" .
            "# Extent description\n" .
            "RW $sz VMFS \"$imageName\"\n" .
            "\n" .
            "# The Disk Data Base\n" .
            "#DDB\n" .
            "\n" .
            "ddb.virtualHWVersion = \"4\"\n" .
            "\n" .
            "ddb.geometry.cylinders=\"$cylinders\"\n" .
            "ddb.geometry.heads=\"255\"\n" .
            "ddb.geometry.sectors=\"63\"\n" .
            "ddb.uuid.image=\"$uuid\"\n" .
            "ddb.uuid.parent=\"00000000-0000-0000-0000-000000000000\"\n" .
            "ddb.uuid.modification=\"00000000-0000-0000-0000-000000000000\"\n" .
            "ddb.uuid.parentmodification=\"00000000-0000-0000-0000-000000000000\"\n";

        $this->filesystem->filePutContents($vmdkPath, $vmdkContents);
        $this->filesystem->chmod($vmdkPath, 0666);
    }
}
