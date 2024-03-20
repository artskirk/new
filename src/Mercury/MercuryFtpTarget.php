<?php

namespace Datto\Mercury;

use Datto\Log\LoggerAwareTrait;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Common\Resource\Sleep;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Class for managing exported mercury FTP volumes.
 * @author Christopher Bitler <cbitler@datto.com>
 */
class MercuryFtpTarget implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const TARGET_NAME_FORMAT = 'iqn.%s.net.datto.dev.temp.%s.%s%s';
    const TARGET_DATE = '2007-01';
    const STARTUP_TIMEOUT_SECONDS = 5;

    private MercuryFtpService $mercuryFtpService;
    private LockFactory $lockFactory;
    private Sleep $sleep;
    private Lock $mercuryFtpConfLock;

    public function __construct(
        MercuryFtpService $mercuryFtpService,
        Sleep $sleep,
        LockFactory $lockFactory
    ) {
        $this->mercuryFtpService = $mercuryFtpService;
        $this->sleep = $sleep;
        $this->lockFactory = $lockFactory;
        $this->mercuryFtpConfLock = $this->lockFactory->getProcessScopedLock(MercuryFtpService::CONFIG_PATH);
    }

    /**
     * Creates a target
     */
    public function createTarget(string $targetName, array $lunPaths, string $password = null): void
    {
        try {
            $this->mercuryFtpConfLock->exclusive();

            $this->mercuryFtpService->addTarget($targetName, $password);

            foreach ($lunPaths as $index => $path) {
                $this->mercuryFtpService->addLun($targetName, $index, $path);
            }
        } finally {
            $this->mercuryFtpConfLock->unlock();
        }
    }

    /**
     * Deletes a target.
     *
     * @param string $targetName The name of the target to delete
     */
    public function deleteTarget(string $targetName): void
    {
        try {
            $this->mercuryFtpConfLock->exclusive();

            $targetExists = array_key_exists($targetName, $this->mercuryFtpService->listTargets());
            if (!$targetExists) {
                throw new MercuryTargetDoesNotExistException($targetName);
            }

            $this->mercuryFtpService->deleteTarget($targetName);
        } finally {
            $this->mercuryFtpConfLock->unlock();
        }
    }

    /**
     * Gets the `mercuryftpctl list` output for a single target.
     */
    public function getTarget(string $targetName): TargetInfo
    {
        try {
            $this->mercuryFtpConfLock->exclusive();

            $targets = $this->mercuryFtpService->listTargets();
            $targetExists = array_key_exists($targetName, $targets);
            if (!$targetExists) {
                throw new MercuryTargetDoesNotExistException($targetName);
            }

            /*
             * Example mercuryftpctl list output:
             * {
             *   "iqn.2007-01.net.datto.dev.temp.hostname.1abb90f60c8c497eb4f2bc86ef218689-1548712808-differential-rollback": {
             *     "0": "/dev/loop1p1",
             *     "1": "/dev/loop2p1",
             *     "password": "B8jWMUEId3Z19mNW2ydezosO2McvdJoz"
             *   }
             * }
             */
            return $this->targetInfoFromListOutput($targetName, $targets[$targetName]);
        } finally {
            $this->mercuryFtpConfLock->unlock();
        }
    }

    /**
     * Make a proper name for a target out of an agent name.
     *
     * @param string $assetKey hostname of the agent
     * @param string $prefix Prefix to use for the target name
     */
    public function makeTargetNameTemp($assetKey, $prefix = 'agent'): string
    {
        $deviceHostname = gethostname();
        return sprintf(
            self::TARGET_NAME_FORMAT,
            self::TARGET_DATE,
            strtolower($deviceHostname),
            $prefix,
            strtolower($assetKey)
        );
    }

    /**
     * Make a proper name for a target out of restore information.
     */
    public function makeRestoreTargetName(string $assetKey, int $snapshot, string $suffix): string
    {
        $deviceHostname = gethostname();
        $restore = "$assetKey-$snapshot-$suffix";

        return sprintf(
            self::TARGET_NAME_FORMAT,
            self::TARGET_DATE,
            strtolower($deviceHostname),
            '',
            strtolower($restore)
        );
    }

    /**
     * Start mercuryftpd if dead
     */
    public function startIfDead(): void
    {
        if (!$this->mercuryFtpService->isAlive()) {
            $this->mercuryFtpService->start();
        }

        $secondsPassed = 0;
        while ($secondsPassed < self::STARTUP_TIMEOUT_SECONDS) {
            if ($this->mercuryFtpService->isReady()) {
                return;
            }

            $secondsPassed++;
            $this->sleep->sleep(1);
        }

        throw new Exception("Mercury FTP failed to start.");
    }

    /**
     * Create a TargetInfo from mercuryftpctl's json output.
     *
     * Output example:
     * {
     *   "0": "/dev/loop1p1",
     *   "1": "/dev/loop2p1",
     *   "password": "B8jWMUEId3Z19mNW2ydezosO2McvdJoz"
     * }
     *
     * @param string $targetName
     * @param array $output
     */
    private function targetInfoFromListOutput(string $targetName, array $output): TargetInfo
    {
        $password = $output['password'] ?? null;

        $luns = [];

        // mercuryftpctl puts password and the lun list at the same depth for some reason
        foreach ($output as $key => $value) {
            if (is_numeric($key)) {
                $luns[$key] = $value;
            }
        }

        return new TargetInfo(
            $targetName,
            $luns,
            $password
        );
    }
}
