<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Azure\Storage\AzCopy;
use Datto\Azure\Storage\AzCopyContextFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\Export\Stages\AbstractStage;
use Exception;

/**
 * Uploads exported VHDs to the public cloud.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudUploadStage extends AbstractStage
{
    private const AZCOPY_LOG_LOCATION = '/var/log/azcopy';
    private const AZCOPY_JOB_PLAN_LOCATION = '/var/lib/azcopy';

    private AzCopyContextFactory $azCopyContextFactory;
    private AzCopy $azCopy;

    public function __construct(
        AzCopyContextFactory $azCopyContextFactory,
        AzCopy $azCopy
    ) {
        $this->azCopyContextFactory = $azCopyContextFactory;
        $this->azCopy = $azCopy;
    }

    /**
     * @inheritDoc
     */
    public function commit()
    {
        $this->azCopy->setLogger($this->logger);
        $exportedFiles = $this->context->getExportedFiles();
        $sasURIMap = $this->context->getSasUriMap();
        $assetKey = $this->context->getAgent()->getKeyName();
        $azCopyStatusId = $this->context->getStatusId();
        $fullPathSasUriMap = $this->getFullPathSasUriMap($exportedFiles, $sasURIMap);

        $this->logger->setAssetContext($assetKey);

        $azCopyContext = $this->azCopyContextFactory->createWithLogLocations(
            $azCopyStatusId,
            self::AZCOPY_LOG_LOCATION,
            self::AZCOPY_JOB_PLAN_LOCATION
        );
        $this->azCopy->uploadFiles(
            $azCopyContext,
            $fullPathSasUriMap
        );
    }

    /**
     * @inheritDoc
     */
    public function cleanup()
    {
        // Nothing to do
    }

    /**
     * @inheritDoc
     */
    public function rollback()
    {
        // Nothing to do
    }

    private function getFullPathSasUriMap(array $exportedFiles, array $sasURIMap)
    {
        $fullPathSasURIMap = [];
        foreach ($exportedFiles as $file) {
            $pathParts = pathinfo($file);
            $filename = $pathParts['filename'];

            if (isset($sasURIMap[$filename])) {
                $fullPathSasURIMap[$file] = $sasURIMap[$filename];
            } else {
                throw new Exception("$filename was not found in the SAS URI Map");
            }
        }
        return $fullPathSasURIMap;
    }
}
