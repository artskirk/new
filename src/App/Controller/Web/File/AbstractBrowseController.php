<?php

namespace Datto\App\Controller\Web\File;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\ProcessFactory;
use Datto\File\FileEntry;
use Datto\File\FileEntryService;
use Datto\Filesystem\SearchService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\Process\Process;
use Exception;

/**
 * Common functionality to share and file restore browsing.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
abstract class AbstractBrowseController extends AbstractBaseController
{
    protected DeviceLoggerInterface $logger;
    protected Filesystem $filesystem;
    protected FileEntryService $fileEntryService;
    protected SearchService $searchService;
    protected ProcessFactory $processFactory;
    protected MimeTypesInterface $mimeTypesInterface;

    public function __construct(
        NetworkService $networkService,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        FileEntryService $fileEntryService,
        SearchService $searchService,
        ProcessFactory $processFactory,
        MimeTypesInterface $mimeTypesInterface,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->fileEntryService = $fileEntryService;
        $this->searchService = $searchService;
        $this->processFactory = $processFactory;
        $this->mimeTypesInterface = $mimeTypesInterface;
    }

    /**
     * Returns a Symfony Response that downloads a single file or streams a zip for a directory.
     *
     * TODO This should not be in a controller!
     *
     * @param string $path
     * @return Response
     */
    protected function download(string $path): Response
    {
        if ($this->filesystem->isDir($path)) {
            $response = new StreamedResponse(function () use ($path) {
                $process = $this->processFactory
                    ->getFromShellCommandLine('find "${:FINDPATH}" -type f -or -type d | zip --names-stdin --quiet -')
                    ->setWorkingDirectory(dirname($path))
                    ->setTimeout(null);

                $process->run(function ($type, $buffer) {
                    if ($type === Process::ERR) {
                        $this->logger->warning('ABC0001 Process error', ['buffer' => $buffer]);
                    } else {
                        echo $buffer;
                    }
                }, ['FINDPATH' => basename($path)]);
            });

            $sanitizedFilename = $this->removeQuotes(basename($path));
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Transfer-Encoding', 'binary');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $sanitizedFilename . '.zip"');
        } else {
            $response = new BinaryFileResponse($path);

            try {
                $mime = $this->mimeTypesInterface->guessMimeType($path);
            } catch (Exception $ex) {
                $this->logger->critical('ABC0002 Could not determine MIME type', ['exception' => $ex]);
            }

            $mime = $mime ?? 'application/octet-stream';

            $response->headers->set('Content-Type', $mime);
            $response->headers->set('Content-Transfer-Encoding', 'binary');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        }
        return $response;
    }

    /**
     * Remove all double quotes from a string, for use in Content-Disposition filename.
     *
     * @param string $filename
     * @return string
     */
    private function removeQuotes(string $filename): string
    {
        return preg_replace('/"/', '', $filename);
    }

    /**
     * Converts an array of FileEntry objects to their array counterparts.
     *
     * @param FileEntry[] $fileEntries
     * @return array
     */
    protected function fileEntriesToArrays(array $fileEntries): array
    {
        $arrays = [];

        foreach ($fileEntries as $fileEntry) {
            $arrays[] = $fileEntry->toArray();
        }

        return $arrays;
    }
}
