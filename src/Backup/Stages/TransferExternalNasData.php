<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Share\ExternalNas\ExternalNasService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\ShareException;
use Datto\Backup\BackupCancelledException;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * This backup stage transfers data from the external samba share to our share
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class TransferExternalNasData extends BackupStage implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var ExternalNasService */
    private $externalNasService;
    
    public function __construct(ExternalNasService $externalNasService)
    {
        $this->externalNasService = $externalNasService;
    }

    public function commit()
    {
        /** @var ExternalNasShare $share */
        $share = $this->context->getAsset();

        $this->logger->info("ENS0011 Starting external share copy");
        $liveDatasetPath = $share->getDataset()->getMountPoint();
        try {
            $this->externalNasService->copyShare($share->getKeyName(), $share->getSambaMount(), $liveDatasetPath);
            $this->logger->info("ENS0012 Finished external share copy");
        } catch (BackupCancelledException $bce) {
            throw $bce;
        } catch (ShareException $shareException) {
            throw $shareException;
        } catch (Throwable $e) {
            $this->logger->error('ENS0013 Failed to copy external share.', ['exception' => $e]);
            throw $e;
        }
    }

    public function cleanup()
    {
        // nothing
    }
}
