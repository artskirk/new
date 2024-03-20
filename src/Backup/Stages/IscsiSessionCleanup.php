<?php

namespace Datto\Backup\Stages;

use Datto\Iscsi\RemoteInitiatorFactory;
use Datto\Util\RetryHandler;
use Throwable;

/**
 * This backup stage cleans up any unused iscsi initiator sessions from the protected systems.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class IscsiSessionCleanup extends BackupStage
{
    const ISCSI_LOGOUT_RETRIES = 4;
    const ISCSI_LOGOUT_RETRY_INTERVAL = 2;

    /** @var RemoteInitiatorFactory */
    private $remoteInitiatorFactory;

    /** @var RetryHandler */
    private $retryHandler;

    public function __construct(
        RemoteInitiatorFactory $remoteInitiatorFactory,
        RetryHandler $retryHandler
    ) {
        $this->remoteInitiatorFactory = $remoteInitiatorFactory;
        $this->retryHandler = $retryHandler;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        try {
            $this->context->getLogger()->debug('ISC0039 Telling iSCSI initiator to logout of unused sessions...');
            $this->cleanupUnusediSCSISessions();
        } catch (Throwable $exception) {
            $this->context->getLogger()->error('ISC0040 Failed to cleanup unused iSCSI sessions on agent side: ' . $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Check for any unused initiator sessions (no connections), and logout of them in order to prevent the
     * initiator from attempting to reconnect to a non-existent target.
     */
    private function cleanupUnusediSCSISessions()
    {
        $initiator = $this->remoteInitiatorFactory->create($this->context->getAsset()->getKeyName(), $this->context->getLogger());
        $unusedSessions = $initiator->listUnusedSessions();
        foreach ($unusedSessions as $session) {
            $sessionId = $session['SessionId'];
            $this->context->getLogger()->debug("ISC0037 Trying to logout from unused iSCSI session: $sessionId ...");
            if (!$this->attemptLogoutFromSession($sessionId)) {
                $this->context->getLogger()->error("ISC0038 Unable to cleanup unused iSCSI session, attempts exhausted");
            }
        }
    }

    /**
     * Attempts to logout from an iSCSI session a number of times
     *
     * @param string $sessionId
     * @return bool
     */
    private function attemptLogoutFromSession(string $sessionId): bool
    {
        $initiator = $this->remoteInitiatorFactory->create($this->context->getAsset()->getKeyName(), $this->context->getLogger());

        try {
            $this->retryHandler->executeAllowRetry(
                function () use ($sessionId, $initiator) {
                    $initiator->logoutFromSession($sessionId);
                    $this->context->getLogger()->debug("ISC0035 Session: $sessionId logged out successfully");
                },
                self::ISCSI_LOGOUT_RETRIES,
                self::ISCSI_LOGOUT_RETRY_INTERVAL
            );
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }
}
