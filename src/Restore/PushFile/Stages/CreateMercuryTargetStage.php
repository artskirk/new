<?php

namespace Datto\Restore\PushFile\Stages;

use Datto\Log\SanitizedException;
use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryTargetDoesNotExistException;
use Datto\Restore\PushFile\AbstractPushFileRestoreStage;
use Datto\Security\PasswordGenerator;
use Throwable;

/**
 * Creates a mercuryftp target and attaches the zip.
 *
 * Ryan Mack <rmack@datto.com>
 */
class CreateMercuryTargetStage extends AbstractPushFileRestoreStage
{
    const SECRET_LENGTH = 32;

    private MercuryFtpTarget $mercuryFtpTarget;

    public function __construct(
        MercuryFtpTarget $mercuryFtpTarget
    ) {
        $this->mercuryFtpTarget = $mercuryFtpTarget;
    }

    public function commit()
    {
        $assetKey = $this->context->getAgent()->getKeyName();
        $snapshot = $this->context->getSnapshot();
        $cloneSpec = $this->context->getCloneSpec();
        $restoreType = $cloneSpec->getSuffix();

        $targetName = $this->mercuryFtpTarget->makeRestoreTargetName($assetKey, $snapshot, $restoreType);

        $lunPaths[$this->context->getLun()] = $this->context->getZipPath();

        $password = PasswordGenerator::generate(self::SECRET_LENGTH);

        $this->logger->info('CMS0001 Creating MercuryFTP target...', ['targetName' => $targetName]);

        try {
            $this->mercuryFtpTarget->createTarget($targetName, $lunPaths, $password);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$password]);
        }

        $this->context->setTargetInfo($this->mercuryFtpTarget->getTarget($targetName));
    }

    public function cleanup()
    {
        $targetName = $this->context->getTargetInfo()->getName();

        $this->logger->info('CMS0002 Deleting MercuryFTP target...', ['targetName' => $targetName]);

        try {
            $this->mercuryFtpTarget->deleteTarget($targetName);
        } catch (MercuryTargetDoesNotExistException $e) {
            $this->logger->warning('CMS0003 Target does not exist, continuing ...');
        }
    }
}
