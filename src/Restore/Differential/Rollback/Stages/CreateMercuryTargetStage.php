<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryTargetDoesNotExistException;
use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Security\PasswordGenerator;
use Datto\Log\DeviceLoggerInterface;

/**
 * Creates a mercuryftp target and attaches the agent's volumes.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class CreateMercuryTargetStage extends AbstractStage
{
    /** @var MercuryFtpTarget */
    private $mercuryFtpTarget;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param MercuryFtpTarget $mercuryFtpTarget
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        MercuryFtpTarget $mercuryFtpTarget
    ) {
        parent::__construct($context, $logger);

        $this->mercuryFtpTarget = $mercuryFtpTarget;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $snapshot = $this->context->getSnapshot();
        $restoreType = $this->context->getCloneSpec()->getSuffix();

        $targetName = $this->mercuryFtpTarget->makeRestoreTargetName($assetKey, $snapshot, $restoreType);
        $lunPaths = $this->context->getLunPaths();
        $password = PasswordGenerator::generate(DifferentialRollbackService::PASSWORD_LENGTH);

        $this->logger->debug("DFR0002 Creating MercuryFTP target $targetName ...");

        $this->mercuryFtpTarget->createTarget($targetName, $lunPaths, $password);

        $this->context->setTargetName($targetName);
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $targetName = $this->context->getTargetName();

        $this->logger->debug("DFR0006 Deleting MercuryFTP target $targetName...");

        try {
            $this->mercuryFtpTarget->deleteTarget($targetName);
        } catch (MercuryTargetDoesNotExistException $e) {
            $this->logger->warning('DFR0009 Target does not exist, continuing with remove ...');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // rollback
    }
}
