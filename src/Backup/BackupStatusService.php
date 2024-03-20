<?php

namespace Datto\Backup;

use Datto\Asset\Agent\Agent;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use InvalidArgumentException;

/**
 * Represents the current state of the backup process
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupStatusService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const STATE_ACTIVE = 'active';
    const STATE_IDLE = 'idle';
    const STATE_PREFLIGHT = 'preflight';
    const STATE_SAMBA = 'samba';
    const STATE_QUERY = 'query';
    const STATE_VSS = 'vss';
    const STATE_LOST_CONNECTION = 'lostconnection';
    const STATE_TRANSFER = 'transfer';
    const STATE_PREPARING_ENVIRONMENT = 'preparing_environment';
    const STATE_FILESYSTEM_INTEGRITY = 'filesystem_integrity';
    const STATE_POST = 'post';
    const STATE_CANCEL = 'cancel';
    const STATE_STRING = 'string';

    const STATE_TRANSFER_STEP_PREPARE_IMAGE = 'prepare_image';
    const STATE_TRANSFER_STEP_PREPARE_VOLUME = 'prepare_volume';
    const STATE_TRANSFER_STEP_FINISH_VOLUME = 'finish_volume';
    const STATE_TRANSFER_STEP_TRANSFERRING = 'transferring';

    const NO_EXPIRY = 0;

    /** @var string */
    private $assetKeyName;

    /** @var Filesystem */
    private $filesystem;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        string $assetKeyName,
        DeviceLoggerInterface $logger = null,
        Filesystem $filesystem = null,
        AgentConfigFactory $agentConfigFactory = null,
        PosixHelper $posixHelper = null,
        DateTimeService $dateTimeService = null
    ) {
        $this->assetKeyName = $assetKeyName;
        $processFactory = new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($processFactory);
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->posixHelper = $posixHelper ?: new PosixHelper($processFactory);
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->setLogger($logger ?? new NullLogger());
    }

    /**
     * Update the current backup status
     *
     * @param int $startTime
     * @param string $state
     * @param array $additional
     * @param string|null $backupType
     * @param int $expiry Communicating with a DTC agent is unreliable so this acts to reset a lost connection
     */
    public function updateBackupStatus(
        int $startTime,
        string $state,
        array $additional = [],
        string $backupType = null,
        int $expiry = self::NO_EXPIRY
    ) {
        /** @var int|null $timestamp */
        $timestamp = self::NO_EXPIRY;
        if ($expiry != self::NO_EXPIRY) {
            $timestamp = $this->dateTimeService->getRelative("+" . $expiry . " sec")->getTimestamp();
        }

        $state = [
            'state' => $state,
            'data' => $additional,
            'md5' => md5($this->assetKeyName),
            'started' => $startTime,
            'backupType' => $backupType,
            'expiryTimestamp' => $timestamp
        ];

        $backupStatusFile = $this->getBackupStatusFile();
        $this->filesystem->filePutContents($backupStatusFile, json_encode($state));
    }

    /**
     * Clear the backup status
     */
    public function clearBackupStatus()
    {
        $backupStatusFile = $this->getBackupStatusFile();
        if ($this->filesystem->exists($backupStatusFile)) {
            $this->filesystem->unlink($backupStatusFile);
        }
    }

    /**
     * Get the backup status.
     *
     * @param bool $checkIfProcessAlive // FIXME: Hack to bypass 'is process alive' check, as there is no process
     *                                            for direct-to-cloud agents.
     * @return BackupStatus
     * @throws InvalidArgumentException
     */
    public function get(bool $checkIfProcessAlive = true): BackupStatus
    {
        $agentConfig = $this->agentConfigFactory->create($this->assetKeyName);
        $backupStatusFile = $this->getBackupStatusFile();

        if (!$this->filesystem->exists($backupStatusFile)) {
            return self::idle();
        }

        if ($checkIfProcessAlive) {
            //FIXME: Odd logic to clear the snapLock file if contains a 0 or 1.
            $pid = $agentConfig->get('snapLock', null);
            if ($pid !== null && intval($pid) <= 1) {
                $agentConfig->clear('snapLock');
            }

            //FIXME: move pid lookup logic to BackupLock
            $pid = $agentConfig->get('snapLock', null);
            if (!$pid || !$this->posixHelper->kill($pid, 0)) {
                return self::idle();
            }
        }

        $contents = $this->filesystem->fileGetContents($backupStatusFile);
        $data = json_decode($contents, true);
        if ($this->logger && is_null($data)) {
            $this->logger->warning("BSS0001 Failed to decode the backup status contents.", [
                'backupStatusContents' => $contents,
            ]);
            throw new InvalidArgumentException('Expected argument of type "array", "null" given');
        }

        $expiryTimestamp = $data['expiryTimestamp'] ?? self::NO_EXPIRY;
        if ($this->isExpired($expiryTimestamp)) {
            $this->filesystem->unlink($backupStatusFile);
            return self::idle();
        }

        return $this->normalize($data);
    }

    /**
     * @return BackupStatus
     */
    public static function idle(): BackupStatus
    {
        return new BackupStatus(self::STATE_IDLE, []);
    }

    /**
     * @param int $expiryTimestamp
     * @return bool
     */
    private function isExpired(int $expiryTimestamp): bool
    {
        if ($expiryTimestamp === self::NO_EXPIRY) {
            return false;
        }
        return ($expiryTimestamp - (new DatetimeService())->getTime()) < 0;
    }

    /**
     * @param array $data
     * @return BackupStatus
     */
    private function normalize(array $data): BackupStatus
    {
        $state = $data['state'] ?? null;
        $additional = [];
        $backupType = $data['backupType'] ?? null;

        switch ($data['state']) {
            case self::STATE_LOST_CONNECTION:
                $additional = [
                    'waitingFor' => $data['data']['limit']
                ];
                break;
            case self::STATE_TRANSFER:
                if (!isset($data['data']['total']) || $data['data']['total'] == 0 || $data['data']['total'] == '') {
                    $additional = [
                        'step' => self::STATE_TRANSFER_STEP_PREPARE_IMAGE
                    ];
                } elseif ($data['data']['sent'] > $data['data']['total']) {
                    $additional = [
                        'step' => self::STATE_TRANSFER_STEP_PREPARE_VOLUME
                    ];
                } elseif ($data['data']['total'] > 0 && $data['data']['sent'] == $data['data']['total']) {
                    $additional = [
                        'step' => self::STATE_TRANSFER_STEP_FINISH_VOLUME
                    ];
                } else {
                    $additional = [
                        'step' => self::STATE_TRANSFER_STEP_TRANSFERRING,
                        'time' => $data['data']['time'],
                        'transferStart' => $data['data']['transferStart'],
                        'sent' => $data['data']['sent'],
                        'total' => $data['data']['total']
                    ];
                }
                break;
            case self::STATE_STRING:
                $additional = [
                    'string' => $data['data']['string']
                ];
                break;
        }

        return new BackupStatus($state, $additional, $backupType);
    }

    /**
     * Get the full path of the backup status file
     *
     * @return string
     */
    private function getBackupStatusFile(): string
    {
        return Agent::KEYBASE . $this->assetKeyName . ".snapStateJSON";
    }
}
