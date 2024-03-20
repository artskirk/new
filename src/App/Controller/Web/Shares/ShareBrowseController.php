<?php

namespace Datto\App\Controller\Web\Shares;

use Datto\App\Controller\Web\File\AbstractBrowseController;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\ShareService;
use Datto\Common\Resource\ProcessFactory;
use Datto\File\FileEntryService;
use Datto\Filesystem\SearchService;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypesInterface;

/**
 * Browse and download file and folders from a file share.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ShareBrowseController extends AbstractBrowseController
{
    private ShareService $shareService;

    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        FileEntryService $fileEntryService,
        SearchService $searchService,
        ProcessFactory $processFactory,
        ShareService $shareService,
        MimeTypesInterface $mimeTypesInterface,
        NetworkService $networkService,
        ClfService $clfService
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
        $this->shareService = $shareService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param string $assetKey
     * @param string $path
     * @return Response
     */
    public function browseAction(string $assetKey, string $path): Response
    {
        try {
            /** @var NasShare $share */
            $share = $this->shareService->get($assetKey);
        } catch (Exception $e) {
            $this->logger->error('SBC0001 Error getting NAS share', ['exception' => $e]);
        }

        if (!isset($share) || !$share instanceof NasShare || $share->getMountPath() === null) {
            return $this->redirectToRoute('shares_index');
        }

        // Check for directory traversal
        $mountpoint = $share->getMountPath();
        if ($this->filesystem->isDirectoryTraversalAttack($mountpoint, $path)) {
            $this->logger->warning('SBC0002 Detected directory traversal, folder might not exist.', ['path' => $path]);
            return $this->redirectToRoute('shares_browse', ['assetKey' => $assetKey, 'path' => '']);
        }

        $fileEntries = $this->fileEntryService->getFileEntriesFromDir($mountpoint, $path);

        foreach ($fileEntries as $fileEntry) {
            $urlArgs = [
                'assetKey' => $assetKey,
                'path' => ltrim($fileEntry->getRelativePath(), '/')
            ];
            $fileEntry->setBrowseHref($this->generateUrl('shares_browse', $urlArgs));
            $fileEntry->setDownloadHref($this->generateUrl('shares_download', $urlArgs));
        }

        // ensure that $path has a / on the end
        $path = rtrim($path, '/') . '/';

        return $this->render(
            'File/browse.html.twig',
            array(
                'assetType' => $share->getType(),
                'displayName' => $share->getDisplayName(),
                'files' => $this->fileEntriesToArrays($fileEntries),
                'restorePath' => $path,
                'translations' => [
                    'browseType' => 'file.browse.header.file.browser',
                    'exit' => 'file.browse.share.exit'
                ],
                'urls' => [
                    'downloadAll' => $this->generateUrl('shares_download', ['assetKey' => $assetKey, 'path' => '']),
                    'previousDirectory' => $this->generateUrl('shares_browse', ['assetKey' => $assetKey, 'path' => $path . '../']),
                    'return' => $this->generateUrl('shares_index'),
                    'search' => $this->generateUrl('shares_search', ['assetKey' => $assetKey, 'searchString' => ''])
                ]
            )
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param string $assetKey
     * @param string $searchString
     * @return Response
     */
    public function searchAction(string $assetKey, string $searchString): Response
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($assetKey);
        if (!$share || !$share instanceof NasShare || $share->getMountPath() === null) {
            return $this->redirectToRoute('shares_index');
        }

        // Check for directory traversal
        $mountpoint = $share->getMountPath();
        $relativeFilePaths = $this->searchService->search($mountpoint, $searchString);
        $fileEntries = $this->fileEntryService->getFileEntries($mountpoint, $relativeFilePaths);

        foreach ($fileEntries as $fileEntry) {
            $urlArgs = [
                'assetKey' => $assetKey,
                'path' => ltrim($fileEntry->getRelativePath(), '/')
            ];
            $fileEntry->setBrowseHref($this->generateUrl('shares_browse', $urlArgs));
            $fileEntry->setDownloadHref($this->generateUrl('shares_download', $urlArgs));
        }

        return $this->render(
            'File/search.html.twig',
            [
                'assetType' => $share->getType(),
                'displayName' => $share->getName(),
                'keyName' => $share->getKeyName(),
                'files' => $this->fileEntriesToArrays($fileEntries),
                'searchString' => $searchString,
                'translations' => [
                    'browseType' => 'file.browse.header.file.browser',
                    'exit' => 'file.browse.share.exit',
                    'returnToBase' => 'file.browse.share.return'
                ],
                'urls' => [
                    'backToFiles' => $this->generateUrl('shares_browse', ['assetKey' => $assetKey, 'path' => '']),
                    'downloadAll' => $this->generateUrl('shares_download', ['assetKey' => $assetKey, 'path' => '']),
                    'return' => $this->generateUrl('shares_index'),
                    'search' => $this->generateUrl('shares_search', ['assetKey' => $assetKey, 'searchString' => ''])
                ]
            ]
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param string $assetKey
     * @param string $path
     * @return Response
     */
    public function downloadAction(string $assetKey, string $path): Response
    {
        $share = $this->shareService->get($assetKey);
        if (!$share || !$share instanceof NasShare || $share->getMountPath() === null) {
            return $this->redirectToRoute('shares_index');
        }

        // Check for directory traversal
        $mountPoint = $share->getMountPath();
        if ($this->filesystem->isDirectoryTraversalAttack($mountPoint, $path)) {
            $this->logger->warning('SBC0003 Detected directory traversal, folder might not exist.', ['path' => $path]);
            return $this->redirectToRoute('shares_browse', ['assetKey' => $assetKey, 'path' => '']);
        }

        $fullPath = $this->filesystem->join($mountPoint, $path);
        return $this->download($fullPath);
    }
}
