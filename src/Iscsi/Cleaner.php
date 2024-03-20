<?php

namespace Datto\Iscsi;

use Datto\Asset\Agent\DmCryptManager;
use Datto\Log\LoggerFactory;
use Datto\Util\RetryHandler;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Performs cleanup related tasks for encrypted agents
 *
 * @author Dan Fuhry <dfuhry@datto.com>
 * @author Justin Giacobbi <justin@datto.com>
 */
class Cleaner
{
    const ISCSI_DELETE_TARGET_RETRIES = 4;
    const ISCSI_DELETE_TARGET_RETRY_INTERVAL = 2;
    const ISCSI_LOGOUT_TARGET_RETRIES = 5;
    const ISCSI_LOGOUT_TARGET_RETRY_INTERVAL = 1;

    /** @var string */
    protected $hostname;

    /**
     * located in web/includes
     * @var IscsiTarget
     */
    protected $iscsi;

    /** @var RemoteInitiator */
    protected $initiator;

    /** @var DmCryptManager */
    protected $dmCrypt;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var RetryHandler */
    private $retryHandler;

    /**
     * @param string $hostname
     * @param IscsiTarget $target
     * @param RemoteInitiator $initiator
     * @param DmCryptManager $dmCrypt
     * @param DeviceLoggerInterface $logger
     * @param RetryHandler $retryHandler
     */
    public function __construct(
        $hostname,
        IscsiTarget $target,
        RemoteInitiator $initiator = null,
        DmCryptManager $dmCrypt = null,
        DeviceLoggerInterface $logger = null,
        RetryHandler $retryHandler = null
    ) {
        $this->hostname = $hostname;
        $this->iscsi = $target;
        $this->initiator = $initiator ?: new RemoteInitiator($hostname);
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->dmCrypt = $dmCrypt ?: new DmCryptManager(null, null, null, null, $this->logger);
        $this->retryHandler = $retryHandler ?: new RetryHandler();
    }

    /**
     * Gets the iSCSI target names created for given agent.
     *
     * @return string[]
     */
    public function getAgentISCSITargetNames()
    {
        $agentTargets = array();
        foreach ($this->iscsi->listTargets() as $tn) {
            list(,$localPortion) = explode(':', $tn, 2);
            $targetAgentName = preg_replace(
                '/^agents\./',
                '',
                preg_replace('/-[a-f0-9]{8}$/', '', $localPortion)
            );

            if ($targetAgentName !== strtolower($this->hostname)
                && $targetAgentName !== 'agent' . strtolower($this->hostname)
            ) {
                continue;
            }

            $agentTargets[] = $tn;
        }

        return $agentTargets;
    }

    /**
     * Prunes registered iSCSI targets on the iSCSI initiator.
     */
    public function pruneiSCSIInitiator()
    {
        $this->logger->info('ISC0030 Pruning iSCSI targets', ['hostname' => $this->hostname]);

        $attachedTargets = $this->initiator->listDevices();

        foreach ($attachedTargets as &$t) {
            $attachedTargets[$t->getTargetName()] =& $t;
        }
        unset($t);

        $agentTargets = $this->getAgentISCSITargetNames();

        // iterate through each iSCSI target on this system
        foreach ($agentTargets as $tn) {
            // offline the disk
            if (isset($attachedTargets[$tn])) {
                $iSCSI_volume =& $attachedTargets[$tn];

                if ($iSCSI_volume->getTargetName() === $tn) {
                    if (preg_match(
                        '#^\\\\\\\\.\\\\PhysicalDrive([0-9]+)$#',
                        $iSCSI_volume->getLegacyName(),
                        $match
                    )) {
                        $driveNumber = intval($match[1]);
                        $this->initiator->offlineDisk($driveNumber);
                    }
                }

                unset($iSCSI_volume);
            }

            try {
                $this->retryHandler->executeAllowRetry(function () use ($tn) {
                    $this->initiator->logoutFromTarget($tn);
                }, self::ISCSI_LOGOUT_TARGET_RETRIES, self::ISCSI_LOGOUT_TARGET_RETRY_INTERVAL);
            } catch (Throwable $exception) {
                // Swallow the exception, since this is not a critical enough error to stop us from proceeding
            }
        }
    }

    /**
     * Prunes the iSCSI targets related with the agent.
     * It handles the retrying logic.
     */
    public function pruneAgentIscsiTargets()
    {
        $agentTargets = $this->getAgentISCSITargetNames();

        foreach ($agentTargets as $targetName) {
            $this->logger->info('ISC2040 Trying to detach target', ['targetName' => $targetName]);
            if (!$this->attemptDeleteTarget($targetName)) {
                $this->logger->error("ISC2038 Error detaching target, attempts exhausted");
            }
        }
    }

    /**
     * Attempts to delete an iSCSI target a number of times.
     *
     * @todo move this function to a common iSCSI class where it can be used by multiple types of agents.
     * @param string $targetName
     * @return bool
     */
    private function attemptDeleteTarget(string $targetName): bool
    {
        try {
            $this->retryHandler->executeAllowRetry(function () use ($targetName) {
                $this->iscsi->deleteTarget($targetName);
                $this->logger->info('ISC2031 Target detached successfully', ['targetName' => $targetName]);
            }, self::ISCSI_DELETE_TARGET_RETRIES, self::ISCSI_DELETE_TARGET_RETRY_INTERVAL);
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }
}
