<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\Feature\FeatureService;
use Datto\File\FileEntry;
use Datto\Restore\File\FileRestoreService;
use Datto\Utility\Security\SecretString;

/**
 * API endpoints for managing file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class File
{
    const MIN_TRAVERSAL_DEPTH = 1;
    const MAX_TRAVERSAL_DEPTH = 3;

    /** @var FileRestoreService */
    private $fileRestoreService;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        FileRestoreService $fileRestoreService,
        FeatureService $featureService
    ) {
        $this->fileRestoreService = $fileRestoreService;
        $this->featureService = $featureService;
    }

    /**
     * Create a file restore.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_WRITE")
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string|null $passphrase
     * @param bool $withSftp whether or not the file restore will be accessible via sftp
     * @return array
     */
    public function create(string $assetKey, int $snapshot, string $passphrase = null, bool $withSftp = false): array
    {
        $passphrase = $passphrase ? new SecretString($passphrase) : null;
        if ($withSftp) {
            $this->featureService->assertSupported(FeatureService::FEATURE_RESTORE_FILE_SFTP);
        }

        $restore = $this->fileRestoreService->create($assetKey, $snapshot, $passphrase, $withSftp);

        return $restore->getOptions();
    }

    /**
     * Remove a file restore.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_WRITE")
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param bool $forced
     * @return bool
     */
    public function remove(string $assetKey, int $snapshot, bool $forced = false): bool
    {
        $performClientCheck = !$forced;
        if ($performClientCheck && $this->fileRestoreService->hasClients($assetKey, $snapshot)) {
            throw new \Exception("Share has open connections");
        }

        $this->fileRestoreService->remove($assetKey, $snapshot);

        return true;
    }

    /**
     * Returns a list of file entries for a restore in the given
     * sub-path.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_READ")
     *
     * @param string $assetKey Asset key name / UUID
     * @param int $snapshot Unix timestamp / snapshot name
     * @param string $path Relative path inside the mount point
     * @param int $depth How deep we should traverse (min: 1, max: 3)
     * @return array List of entries
     */
    public function browse(string $assetKey, int $snapshot, string $path, int $depth = self::MIN_TRAVERSAL_DEPTH): array
    {
        $entries = [];
        $depth = max(self::MIN_TRAVERSAL_DEPTH, min($depth, self::MAX_TRAVERSAL_DEPTH));
        $fileEntries = $this->fileRestoreService->browse($assetKey, $snapshot, $path, $depth);

        foreach ($fileEntries as $fileEntry) {
            $entries[] = $this->serializeFileEntry($fileEntry);
        }

        return $entries;
    }

    /**
     * Create a file download token (and a corresponding token file)
     * to be used by the file download application (used for Cloud Devices).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE_TOKEN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_TOKEN_WRITE")
     *
     * @param string $assetKey Asset key name / UUID
     * @param int $snapshot Unix timestamp / snapshot name
     * @param string $path Relative path inside the mount point
     * @return array Array with token for file download
     */
    public function createToken(string $assetKey, int $snapshot, string $path): array
    {
        $token = $this->fileRestoreService->createToken($assetKey, $snapshot, $path);

        return [
            'token' => $token
        ];
    }

    /**
     * @param FileEntry $fileEntry
     * @return array
     */
    private function serializeFileEntry(FileEntry $fileEntry)
    {
        if ($fileEntry->isLink()) {
            $type = 'link';
        } elseif ($fileEntry->isDir()) {
            $type = 'dir';
        } else {
            $type = 'file';
        }

        $array = [
            'name' => $fileEntry->getName(),
            'path' => $fileEntry->getRelativePath(),
            'size' => $fileEntry->getSize(),
            'modified' => $fileEntry->getModifiedTime(),
            'type' => $type
        ];

        if ($fileEntry->isDir() && $fileEntry->getDirectoryContents() !== null) {
            $contents = [];

            foreach ($fileEntry->getDirectoryContents() as $subEntry) {
                $contents[] = $this->serializeFileEntry($subEntry);
            }

            $array['contents'] = $contents;
        }

        return $array;
    }
}
