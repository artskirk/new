<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\File\AbstractBrowseController;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\File\FileEntryService;
use Datto\Filesystem\SearchService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Service\Breadcrumbs\BreadcrumbsProvider;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypesInterface;

/**
 * Browse and download file and folders from an active file restore.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class FileRestoreBrowseController extends AbstractBrowseController
{
    private AssetService $assetService;
    private RestoreService $restoreService;
    private BreadcrumbsProvider $breadcrumbsProvider;

    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        FileEntryService $fileEntryService,
        SearchService $searchService,
        ProcessFactory $processFactory,
        AssetService $assetService,
        RestoreService $restoreService,
        MimeTypesInterface $mimeTypesInterface,
        NetworkService $networkService,
        ClfService $clfService,
        BreadcrumbsProvider $breadcrumbsProvider
    ) {
        parent::__construct(
            $networkService,
            $logger,
            $filesystem,
            $fileEntryService,
            $searchService,
            $processFactory,
            $mimeTypesInterface,
            $clfService
        );
        $this->assetService = $assetService;
        $this->restoreService = $restoreService;
        $this->breadcrumbsProvider = $breadcrumbsProvider;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_READ")
     *
     * @param string $assetKey
     * @param int $point
     * @param string $path
     * @return Response
     */
    public function browseAction(string $assetKey, int $point, string $path): Response
    {
        // Get the asset & restore
        try {
            $asset = $this->assetService->get($assetKey);
            /** @var Restore $restore */
            $restore = $this->restoreService->find($assetKey, $point, RestoreType::FILE);
        } catch (Exception $e) {
            $this->logger->error('FRB0001 Error finding restore', ['exception' => $e]);
        }

        if (!isset($asset, $restore)) {
            return $this->redirectToRoute('restore');
        }

        $mountpoint = $restore->getMountDirectory();
        if ($this->filesystem->isDirectoryTraversalAttack($mountpoint, $path)) {
            $this->logger->warning('FRB0002 Detected directory traversal, folder might not exist.', ['path' => $path]);
            return $this->redirectToRoute('restore_file_browse', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']);
        }

        $fileEntries = $this->fileEntryService->getFileEntriesFromDir($mountpoint, $path);

        foreach ($fileEntries as $fileEntry) {
            $urlArgs = [
                'assetKey' => $assetKey,
                'point' => $point,
                'path' => ltrim($fileEntry->getRelativePath(), '/')
            ];
            $fileEntry->setBrowseHref($this->generateUrl('restore_file_browse', $urlArgs));
            $fileEntry->setDownloadHref($this->generateUrl('restore_file_download', $urlArgs));
        }

        // ensure that $path has a / on the end
        $path = rtrim($path, '/') . '/';
        $restoreUrl = $this->generateUrl('restore_file_browse', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']);
        $assetName = $asset->getDisplayName();
        $breadcrumbs = $this->breadcrumbsProvider->createBreadcrumbs($path, $restoreUrl, $assetName);

        return $this->render(
            'File/browse.html.twig',
            [
                'breadcrumbs' => $breadcrumbs,
                'assetType' => $asset->getType(),
                'displayName' => $assetName,
                'files' => $this->fileEntriesToArrays($fileEntries),
                'restorePath' => $path,
                'translations' => [
                    'browseType' => 'file.browse.header.file.recovery',
                    'exit' => 'file.browse.file.restore.exit'
                ],
                'urls' => [
                    'backToFiles' => $restoreUrl,
                    'downloadAll' => $this->generateUrl('restore_file_download', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']),
                    'previousDirectory' => $this->generateUrl('restore_file_browse', ['assetKey' => $assetKey, 'point' => $point, 'path' => $path . '../']),
                    'return' => $this->generateUrl('restore'),
                    'search' => $this->generateUrl('restore_file_search', ['assetKey' => $assetKey, 'point' => $point, 'searchString' => ''])
                ]
            ]
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_READ")
     *
     * @param string $assetKey
     * @param int $point
     * @param string $searchString
     * @return Response
     */
    public function searchAction(string $assetKey, int $point, string $searchString): Response
    {
        // Get the asset & restore
        $asset = $this->assetService->get($assetKey);
        /** @var Restore $restore */
        $restore = $this->restoreService->find($assetKey, $point, RestoreType::FILE);
        if (!$asset || !$restore) {
            return $this->redirectToRoute('restore');
        }

        $mountpoint = $restore->getMountDirectory();
        $relativeFilePaths = $this->searchService->search($mountpoint, $searchString);
        $fileEntries = $this->fileEntryService->getFileEntries($mountpoint, $relativeFilePaths);

        foreach ($fileEntries as $fileEntry) {
            $urlArgs = [
                'assetKey' => $assetKey,
                'point' => $point,
                'path' => ltrim($fileEntry->getRelativePath(), '/')
            ];
            $fileEntry->setBrowseHref($this->generateUrl('restore_file_browse', $urlArgs));
            $fileEntry->setDownloadHref($this->generateUrl('restore_file_download', $urlArgs));
        }

        return $this->render(
            'File/search.html.twig',
            [
                'assetType' => $asset->getType(),
                'displayName' => $asset->getDisplayName(),
                'files' => $this->fileEntriesToArrays($fileEntries),
                'searchString' => $searchString,
                'translations' => [
                    'browseType' => 'file.browse.header.file.recovery',
                    'exit' => 'file.browse.file.restore.exit',
                    'returnToBase' => 'file.browse.file.restore.return'
                ],
                'urls' => [
                    'backToFiles' => $this->generateUrl('restore_file_browse', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']),
                    'downloadAll' => $this->generateUrl('restore_file_download', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']),
                    'return' => $this->generateUrl('restore'),
                    'search' => $this->generateUrl('restore_file_search', ['assetKey' => $assetKey, 'point' => $point, 'searchString' => ''])
                ]
            ]
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_READ")
     *
     * @param string $assetKey
     * @param int $point
     * @param string $path
     * @return Response
     */
    public function downloadAction(string $assetKey, int $point, string $path): Response
    {
        /** @var Restore $restore */
        $restore = $this->restoreService->find($assetKey, $point, RestoreType::FILE);
        if (!$restore) {
            return $this->redirectToRoute('restore');
        }

        $mountPoint = $restore->getMountDirectory();
        if ($this->filesystem->isDirectoryTraversalAttack($mountPoint, $path)) {
            $this->logger->warning('FRB0003 Detected directory traversal, folder might not exist.', ['path' => $path]);
            return $this->redirectToRoute('restore_file_browse', ['assetKey' => $assetKey, 'point' => $point, 'path' => '']);
        }

        $fullPath = $this->filesystem->join($mountPoint, $path);
        return $this->download($fullPath);
    }
}
