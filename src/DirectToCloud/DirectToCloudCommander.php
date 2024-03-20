<?php

namespace Datto\DirectToCloud;

use Datto\Asset\UuidGenerator;
use Datto\Utility\File\Lsof;
use Datto\Log\LoggerFactory;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Utility\Process\Ps;
use Exception;

/**
 * "DTC Commander" is a piece of the direct-to-cloud software that lives on devices in the cloud. Agents
 * communicate with "DTC Server" with spawns a "DTC Commander" processes for the duration of their communication.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DirectToCloudCommander
{
    const KILL_RETRIES = 300;
    const RETRY_SLEEP_SECONDS = 1;

    /** @var Ps */
    private $ps;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var Sleep */
    private $sleep;

    /** @var Lsof */
    private $lsof;

    public function __construct(
        Ps $ps,
        PosixHelper $posixHelper,
        LoggerFactory $loggerFactory,
        Sleep $sleep,
        Lsof $lsof
    ) {
        $this->ps = $ps;
        $this->posixHelper = $posixHelper;
        $this->loggerFactory = $loggerFactory;
        $this->sleep = $sleep;
        $this->lsof = $lsof;
    }

    /**
     * Get an estimated number of active backups based on dtccommanders with file handlers on .datto files.
     *
     * FIXME: This is a hacky method purely meant to be used for reporting until we have a better solution in place to
     * track active backups.
     */
    public function getEstimatedActiveBackupCount()
    {
        $commanders = [];

        $entries = $this->lsof->getFilesByProcessName('dtccommander');
        foreach ($entries as $entry) {
            if (preg_match('/\.datto$/', $entry->getName())) {
                $commanders[$entry->getPid()] = true;
            }
        }

        return count($commanders);
    }

    /**
     * Count the number of active dtccommander processes.
     *
     * @return int|void
     */
    public function count()
    {
        return count($this->getCommanderPids('\S+'));
    }

    /**
     * Kill any "DTC Commander" instances for a given asset in order to kill communication and force the agent
     * to return the "mothership" (cloudbase). This is useful for killing in-progress backups and forcing an agent
     * to return to mothership for status.
     *
     * @param string $assetKey
     */
    public function killCommanderForAsset(string $assetKey)
    {
        if (!UuidGenerator::isUuid($assetKey)) {
            throw new Exception("Asset key must be a valid uuid: $assetKey");
        }

        $logger = $this->loggerFactory->getAsset($assetKey);
        $logger->info('DTC0001 Killing dtccommander processes ...');

        $pids = $this->getCommanderPids($assetKey);
        $attempt = 0;

        // TODO this should hard pause the agent when that gets added
        while (!empty($pids) && $attempt++ < self::KILL_RETRIES) {
            foreach ($pids as $pid) {
                $this->posixHelper->kill($pid, PosixHelper::SIGNAL_KILL);
            }

            $this->sleep->sleep(self::RETRY_SLEEP_SECONDS);

            $pids = $this->getCommanderPids($assetKey);
        }

        if (!empty($pids)) {
            $logger->error(
                'DTC0003 Could not kill dtcommander processes',
                ['pids' => $pids, 'attemptNumber' => $attempt]
            );

            throw new Exception('Could not kill dtccommander');
        } else {
            $logger->info('DTC0002 Killed all dtccommander processes', ['attempts' => $attempt]);
        }
    }

    /**
     * @param string $assetKey
     * @return int[]
     */
    private function getCommanderPids(string $assetKey)
    {
        return $this->ps->getPidsFromCommandPattern("/dtccommander\s+$assetKey/");
    }
}
