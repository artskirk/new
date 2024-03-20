<?php

namespace Datto\Backup\Transport;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Api\AgentTransferResult;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryFTPTLSService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Security\PasswordGenerator;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Creates backup transports based on asset type, configuration, and current transfer attempt.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupTransportFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const MINIMUM_AGENT_VERSION_MERCURYFTP = '2.0.5.0';
    private const MINIMUM_AGENT_VERSION_MERCURYFTP_PWD = '2.1.24.0';
    private const PWD_LENGTH = 32;

    private MercuryFtpTarget $mercuryFtpTarget;
    private MercuryFTPTLSService $mercuryFTPTLSService;
    private AgentConfigFactory  $agentConfigFactory;
    private Collector $collector;

    public function __construct(
        MercuryFtpTarget $mercuryFtpTarget,
        MercuryFTPTLSService $mercuryFTPTLSService,
        AgentConfigFactory $agentConfigFactory,
        Collector $collector
    ) {
        $this->mercuryFtpTarget = $mercuryFtpTarget;
        $this->mercuryFTPTLSService = $mercuryFTPTLSService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->collector = $collector;
    }

    /**
     * Creates a backup transport
     *
     * @param Asset $asset
     * @param int $transferAttempt
     * @param AgentTransferResult $agentTransferResult
     * @param bool $previousTransportSetupFailure
     * @return BackupTransport
     */
    public function createBackupTransport(
        Asset $asset,
        int $transferAttempt,
        AgentTransferResult $agentTransferResult,
        bool $previousTransportSetupFailure
    ): BackupTransport {
        if ($asset->isType(AssetType::WINDOWS_AGENT) || $asset->isType(AssetType::LINUX_AGENT)) {
            $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
            if ($agentConfig->has('shadowSnap')) {
                return $this->createWindowsShadowSnapBackupTransport(
                    $asset
                );
            } else {
                return $this->createDattoAgentBackupTransport(
                    $asset,
                    $transferAttempt,
                    $agentTransferResult,
                    $previousTransportSetupFailure
                );
            }
        } elseif ($asset->isType(AssetType::AGENTLESS)) {
            return new LocalFileTransport($this->logger);
        }

        return new IscsiTransport($asset->getKeyName(), $this->logger);
    }

    /**
     * Creates a backup transport for a ShadowSnap Windows agent.
     * If the agent is encrypted, the EncryptedShadowSnapTransport is returned.
     * If not, the SambaTransport is returned.
     *
     * @param Asset $asset
     * @return BackupTransport
     */
    private function createWindowsShadowSnapBackupTransport(
        Asset $asset
    ): BackupTransport {
        /** @var Agent $agent */
        $agent = $asset;
        $isEncrypted = $agent->getEncryption()->isEnabled();
        if ($isEncrypted) {
            $transport = new EncryptedShadowSnapTransport($asset->getKeyName(), $this->logger);
        } else {
            $transport = new SambaTransport($asset->getKeyName());
            $transport->setLogger($this->logger);
        }
        return $transport;
    }

    /**
     * Creates a backup transport for a Datto DWA or DLA agent.
     * If the agent has mercury enabled and the first attempt fails due to connection failure,
     * an Iscsi backup transport will be created and returned as a fallback.
     *
     * @param Asset $asset
     * @param int $transferAttempt
     * @param AgentTransferResult $agentTransferResult
     * @param bool $previousTransportSetupFailure
     * @return BackupTransport
     */
    private function createDattoAgentBackupTransport(
        Asset $asset,
        int $transferAttempt,
        AgentTransferResult $agentTransferResult,
        bool $previousTransportSetupFailure
    ): BackupTransport {
        $firstAttempt = ($transferAttempt === 1);
        $wasConnectionFailure =
            $agentTransferResult === AgentTransferResult::FAILURE_CONNECTION() ||
            $agentTransferResult === AgentTransferResult::FAILURE_BOTH() ||
            $agentTransferResult === AgentTransferResult::FAILURE_UNKNOWN();

        if (!$asset instanceof Agent) {
            throw new Exception('Invalid asset type. Must be an agent');
        }

        // This check is necessary because there is a bug in older versions of DWA that will cause it to take
        // the target name sent for the Mercury FTP request and use it as the target name for the iSCSI target
        // when attempting to back up over iSCSI for the second attempt.
        $agentSupportsMercury = version_compare(
            $asset->getDriver()->getAgentVersion(),
            self::MINIMUM_AGENT_VERSION_MERCURYFTP,
            '>='
        );
        $useMercuryFtp = ($firstAttempt || !$wasConnectionFailure) && !$previousTransportSetupFailure && $agentSupportsMercury;

        if ($useMercuryFtp) {
            return $this->createMercuryFtpTransport($asset);
        } elseif ($agentSupportsMercury) {
            $this->logger->warning('BTF0001 Failing over from MercuryFTP to iSCSI transport');
            $this->collector->increment(Metrics::AGENT_BACKUP_ISCSI_FALLBACK);
        }

        return new IscsiTransport($asset->getKeyName(), $this->logger);
    }

    private function createMercuryFtpTransport(Asset $asset): MercuryFtpTransport
    {
        if (!$asset instanceof Agent) {
            throw new Exception('Invalid asset type. Must be an agent');
        }

        $passwordSupport = version_compare(
            $asset->getDriver()->getAgentVersion(),
            self::MINIMUM_AGENT_VERSION_MERCURYFTP_PWD,
            '>='
        );
        $password = '';
        if ($passwordSupport) {
            $password = PasswordGenerator::generate(self::PWD_LENGTH);
        }
        return new MercuryFtpTransport(
            $asset->getKeyName(),
            $this->logger,
            $this->mercuryFtpTarget,
            $this->mercuryFTPTLSService,
            $password
        );
    }
}
