<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Log\DeviceLoggerInterface;

/**
 * Unseal the asset if it's encrypted.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class UnsealAssetStage extends AbstractStage
{
    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param EncryptionService $encryptionService
     * @param TempAccessService $tempAccessService
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService
    ) {
        parent::__construct($context, $logger);

        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $passphrase = $this->context->getPassphrase();

        $isEncrypted = $this->encryptionService->isEncrypted($assetKey);
        $needsUnseal = !$this->encryptionService->isAgentMasterKeyLoaded($assetKey) &&
            !$this->tempAccessService->isCryptTempAccessEnabled($assetKey);

        if ($isEncrypted && $needsUnseal) {
            $this->logger->debug('DSR0013 Unsealing agent ...');
            $this->encryptionService->decryptAgentKey($assetKey, $passphrase);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        // nothing
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // nothing
    }
}
