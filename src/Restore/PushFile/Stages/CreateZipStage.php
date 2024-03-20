<?php

namespace Datto\Restore\PushFile\Stages;

use Datto\Asset\UuidGenerator;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\PushFile\AbstractPushFileRestoreStage;
use ZipArchive;

/**
 * Prepare the files for file restore by zipping them.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class CreateZipStage extends AbstractPushFileRestoreStage
{
    const ALGORITHM = 'md5';

    private Filesystem $filesystem;

    private string $uuid;

    public function __construct(Filesystem $filesystem, UuidGenerator $uuidGenerator)
    {
        $this->filesystem = $filesystem;
        $this->uuid = $uuidGenerator->get();
    }

    public function commit()
    {
        $filePaths = $this->context->getPushFiles();
        $fileRestoreMountPoint = $this->context->getRestore()->getMountDirectory();
        $this->logger->setAssetContext($this->context->getAgent()->getKeyName());
        $decompressedSize = 0;

        $zip = new ZipArchive();
        $zipLocation = $this->context->getCloneSpec()->getTargetMountpoint() . '/' . $this->uuid . '.zip';
        if ($zip->open($zipLocation, ZipArchive::CREATE) === true) {
            $this->logger->debug('CZS0001 Zip opened: ' . $zipLocation);
            // Add each file to the zip
            foreach ($filePaths as $file) {
                $fullFilePath = $fileRestoreMountPoint . '/' . $file;
                $this->logger->debug('CZS0002 Zip attempting to add: ' . $fullFilePath);
                if ($this->filesystem->exists($fullFilePath)) {
                    if ($zip->addFile($fullFilePath, $file)) {
                        $decompressedSize += $this->filesystem->getSize($fullFilePath);
                    } else {
                        $this->logger->warning('CZS0003 Zip failed to add the file', ['filePath' => $fullFilePath]);
                    }
                } else {
                    $this->logger->warning('CZS0004 File does not exist', ['filePath' => $fullFilePath]);
                }
            }
            if (!$zip->close()) {
                $this->logger->error('CZS0005 Unable to save the zip bundle', ['zipLocation' => $zipLocation]);
                throw new \Exception('Unable to save zip bundle: ' . $zipLocation);
            }

            // When adding files, we attempt to continue if one file fails. If they all failed, we should just abort.
            if ($decompressedSize === 0) {
                $this->logger->error('CZS0006 There are no files in the zip - aborting');
                throw new \Exception('There are no files in the zip to push.');
            }

            $this->context->setZipPath($zipLocation);
            $this->context->setLun(0);
            $this->context->setDecompressedSize($decompressedSize);
            $this->context->setSize($this->filesystem->getSize($zipLocation));
            $this->context->setChecksum(hash_file(self::ALGORITHM, $zipLocation));
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // Nothing - the files will get cleaned up when the clone is destroyed
    }
}
