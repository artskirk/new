<?php

namespace Datto\Restore\File\Stages;

use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Restore\File\SftpCredentials;
use Datto\Restore\RestoreService;
use Datto\Security\PasswordGenerator;
use Datto\Sftp\SftpManager;
use Datto\User\ShadowUser;
use Datto\Log\DeviceLoggerInterface;

/**
 * Create and bind an SFTP account for a file restore
 *
 * @author Marcus Recck <mr@datto.com>
 */
class BindSftpStage extends AbstractFileRestoreStage
{
    const SFTP_USERNAME_TAIL_LENGTH = 12;
    const SFTP_PASSWORD_LENGTH = 16;

    /** @var RestoreService */
    private $restoreService;

    /** @var SftpManager */
    private $sftpManager;

    /** @var ShadowUser */
    private $shadowUser;

    /**
     * @param FileRestoreContext $context
     * @param DeviceLoggerInterface $logger
     * @param RestoreService $restoreService
     * @param SftpManager $sftpManager
     * @param ShadowUser $shadowUser
     */
    public function __construct(
        FileRestoreContext $context,
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        SftpManager $sftpManager,
        ShadowUser $shadowUser
    ) {
        parent::__construct($context, $logger);
        $this->restoreService = $restoreService;
        $this->sftpManager = $sftpManager;
        $this->shadowUser = $shadowUser;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        if (!$this->context->getRepairMode()) {
            $this->logger->info("FIR0012 Creating SFTP user account for file restore ...");

            $user = 'sftp_' . PasswordGenerator::generate(self::SFTP_USERNAME_TAIL_LENGTH);
            $pass = PasswordGenerator::generate(self::SFTP_PASSWORD_LENGTH);

            $this->shadowUser->create($user, $pass);

            $this->context->setSftpCredentials(new SftpCredentials(
                $user,
                $pass
            ));
        } else {
            $user = $this->context->getRestore()->getOptions()['sftp']['username'];
        }
        $assetKey = $this->context->getAsset()->getKeyName();

        $this->logger->info("FIR0013 Binding SFTP user account to file restore ...");
        // do not allow write access to prevent malicious puts
        $this->sftpManager->restrictedMount($user, $assetKey, $this->context->getRestoreMount());

        $this->logger->info("FIR0014 Starting SFTP service if not already running ...");
        $this->sftpManager->startIfUsers();
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $credentials = $this->context->getSftpCredentials();

        // Nothing to roll back if a user hasn't been created
        if ($credentials !== null) {
            $user = $credentials->getUsername();

            $this->logger->notice('FIR0015 Unbinding SFTP user account ...');
            $this->sftpManager->unmount(
                $user,
                $this->context->getAsset()->getKeyName()
            );

            $this->logger->notice('FIR0016 Deleting SFTP user account ...');
            $this->shadowUser->delete($user);

            $this->logger->notice('FIR0017 Stopping SFTP service if applicable ...');
            $this->sftpManager->stopIfNoUsers();

            $this->context->setSftpCredentials(null);
        }
    }
}
