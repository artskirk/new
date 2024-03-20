<?php

namespace Datto\Service\AssetManagement\Create;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\UuidGenerator;
use Datto\Config\AgentStateFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Utility\Screen;
use Datto\Utility\Security\SecretString;
use Exception;
use Throwable;

/**
 * Main entrance service for agent creation.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateAgentService
{
    private const INIT_WAIT_SEC = 10;

    private CreateAgentTransactionFactory $pairTransactionFactory;
    private Screen $screen;
    private UuidGenerator $uuidGenerator;
    private DateTimeService $dateTimeService;
    private Sleep $sleep;
    private EncryptionService $encryptionService;
    private FeatureService $featureService;
    private AgentStateFactory $agentStateFactory;

    public function __construct(
        CreateAgentTransactionFactory $pairTransactionFactory,
        Screen $screen,
        UuidGenerator $uuidGenerator,
        DateTimeService $dateTimeService,
        Sleep $sleep,
        EncryptionService $encryptionService,
        FeatureService $featureService,
        AgentStateFactory $agentStateFactory
    ) {
        $this->pairTransactionFactory = $pairTransactionFactory;
        $this->screen = $screen;
        $this->uuidGenerator = $uuidGenerator;
        $this->dateTimeService = $dateTimeService;
        $this->sleep = $sleep;
        $this->encryptionService = $encryptionService;
        $this->featureService = $featureService;
        $this->agentStateFactory = $agentStateFactory;
    }

    /**
     * Starts the pairing process in the background.
     * Progress can be monitored by calling the getPairProgress() function
     *
     * @param string $offsiteTarget Where to send the offsite data. Either 'cloud', 'noOffsite',
     *     or a deviceid of a p2p device.
     * @param SecretString $password If not blank, this password will be used to encrypt the data.
     * @param string $domainName The hostname or ip address where the system can be reached. Blank for agentless.
     * @param string $moRef The identifier for the VM on an esx host/cluster. Blank for agent based systems.
     * @param string $connectionName Identifies the esx connection to the host/cluster that contains the VM.
     *     Blank for agent based systems.
     * @param string $agentKeyToCopy If not blank, we will copy settings from the agent referred to by this agentKey
     * @param bool $useLegacyKeyName True to create the agent with a legacy keyName (domainName/moRef) instead of a uuid
     * @param bool $fullDisk If true, will force agentless pairing to backup full disk images (UVM/generic)
     * @return string the keyName of the agent to be created
     */
    public function startPair(
        string $offsiteTarget,
        SecretString $password,
        string $domainName,
        string $moRef,
        string $connectionName,
        string $agentKeyToCopy,
        bool $useLegacyKeyName,
        bool $fullDisk
    ): string {
        $uuid = $this->uuidGenerator->get();

        $command = [
            'snapctl',
            'internal:agent:create',
            '--offsiteTarget',
            $offsiteTarget,
            '--uuid',
            $uuid
        ];

        if ($domainName) {
            $command[] = '--domainName';
            $command[] = $domainName;
        } elseif ($moRef && $connectionName) {
            $command[] = '--moRef';
            $command[] = $moRef;
            $command[] = '--connectionName';
            $command[] = $connectionName;
        } else {
            throw new Exception('Either (domainName) or (moRef and connectionName) must be set');
        }

        if ($useLegacyKeyName) {
            $command[] = '--useLegacyKeyName';
        }

        if ($fullDisk) {
            $command[] = '--fullDisk';
        }

        if ($agentKeyToCopy) {
            $command[] = '--agentKeyToCopy';
            $command[] = $agentKeyToCopy;
        }

        $keyName = $this->getKeyName($uuid, $domainName, $moRef, $useLegacyKeyName);

        // We encrypt now instead of in the transaction to avoid exposing the user's password on the cli or filesystem
        if ($password && $this->featureService->isSupported(FeatureService::FEATURE_ENCRYPTED_BACKUPS)) {
            $this->encryptionService->encryptAgent($keyName, $password);
        }

        if ($this->screen->runInBackground($command, 'createAgent-' . $keyName)) {
            do {
                $time = $this->dateTimeService->getTime();
                $this->sleep->msleep(100);

                $progress = $this->getPairProgress($keyName);
                if ($progress['state'] !== CreateAgentProgress::INACTIVE) {
                    return $keyName;
                }
            } while ($time > $this->dateTimeService->getTime() - self::INIT_WAIT_SEC);
        }

        throw new Exception('Could not start agent creation process');
    }

    /**
     * Get pairing progress for processes started by the startPair() method
     *
     * @return array With keys
     *     'progress' => percent of the pairing process that has been completed (0 - 100)
     *     'state' => The current state of the pairing process. Will be one of the constants in CreateAgentProgress.php
     *     'errorMessage' => Full text error or exception messages. Empty string if there is no error
     */
    public function getPairProgress(string $keyName): array
    {
        $agentState = $this->agentStateFactory->create($keyName);
        $createProgress = new CreateAgentProgress();

        $agentState->loadRecord($createProgress);

        return $createProgress->getProgress();
    }

    /**
     * Runs the pairing process.
     * Progress can be monitored by calling the getPairProgress() function
     *
     * @param string $domainName The hostname or ip address where the system can be reached. Blank for agentless.
     * @param string $moRef The identifier for the VM on an esx host/cluster. Blank for agent based systems.
     * @param string $connectionName Identifies the esx connection to the host/cluster that contains the VM.
     *     Blank for agent based systems.
     * @param string $offsiteTarget Where to send the offsite data. Either 'cloud', 'noOffsite',
     *     or a deviceid of a p2p device.
     * @param SecretString $password If not blank, this password will be used to encrypt the data.
     * @param string $agentKeyToCopy If not blank, we will copy settings from the agent referred to by this agentKey
     * @param bool $useLegacyKeyName True to create the agent with a legacy keyName (domainName/moRef) instead of a uuid
     * @param bool $force if true and pairing a ShadowSnap agent, enable SMB minimum version 1 automatically.
     * @param bool $fullDisk if true and pairing an agentless agent, force full disk backup
     * @return string the keyName of the agent to be created
     */
    public function doPair(
        string $uuid,
        string $domainName,
        string $moRef,
        string $connectionName,
        string $offsiteTarget,
        SecretString $password,
        string $agentKeyToCopy,
        bool $useLegacyKeyName,
        bool $force,
        bool $fullDisk
    ): string {
        $keyName = $useLegacyKeyName ? $domainName : $uuid;

        $logger = LoggerFactory::getAssetLogger($keyName);
        $logger->setAssetContext($keyName);

        $logger->info('PAR0201 Starting creation process for Agent');

        $context = new CreateAgentContext(
            $keyName,
            $uuid,
            $offsiteTarget,
            $password,
            $domainName,
            $moRef,
            $connectionName,
            $agentKeyToCopy,
            $force,
            $fullDisk,
            $logger
        );

        $transaction = $this->pairTransactionFactory->create($context);

        $agentState = $this->agentStateFactory->create($keyName);

        $createProgress = new CreateAgentProgress();
        $createProgress->setState(CreateAgentProgress::INIT);
        $agentState->saveRecord($createProgress);

        try {
            $transaction->commit();
        } catch (Throwable $e) {
            $agentState->loadRecord($createProgress);

            // Only set the generic failure state if we haven't set an error already
            if (!CreateAgentProgress::isErrorState($createProgress->getState())) {
                $createProgress->setState(CreateAgentProgress::FAIL);
                $createProgress->setErrorMessage($e->getPrevious()->getMessage());
                $agentState->saveRecord($createProgress);
            }
            throw $e;
        }

        return $keyName;
    }

    /**
     * Determine what the keyName for the newly created asset will be
     */
    private function getKeyName(string $uuid, string $domainName, string $moRef, bool $useLegacyKeyName): string
    {
        $legacyKeyName = empty($domainName) ? $moRef : $domainName;
        return $useLegacyKeyName ? $legacyKeyName : $uuid;
    }
}
