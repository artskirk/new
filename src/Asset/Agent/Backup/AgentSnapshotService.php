<?php

namespace Datto\Asset\Agent\Backup;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Service to get and check for existence of specific backups.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class AgentSnapshotService
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Returns an instance of AgentSnapshot class initialized with the given keyname and epoch.
     *
     * @param string $keyName
     * @param int|string $epoch
     * @return AgentSnapshot
     */
    public function get(string $keyName, $epoch): AgentSnapshot
    {
        if (!$this->exists($keyName, $epoch)) {
            throw new Exception("Backup at $epoch does not exist for $keyName");
        }

        return new AgentSnapshot($keyName, $epoch);
    }

    /**
     * Determines whether or not a snapshot exists.
     *
     * @param string $keyName
     * @param int|string $epoch
     * @return bool
     */
    private function exists(string $keyName, $epoch): bool
    {
        return $this->filesystem->exists(sprintf(
            AgentSnapshotRepository::BACKUP_DIRECTORY_TEMPLATE,
            $keyName,
            $epoch
        ));
    }
}
