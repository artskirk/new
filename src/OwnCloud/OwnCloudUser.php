<?php

namespace Datto\OwnCloud;

use Datto\Common\Utility\Filesystem;

/**
 * User controls for ownCloud users
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class OwnCloudUser
{
    // NOTE: While we should really use LocalConfig here instead of Filesystem,
    // FileConfig::setRaw() uses putAtomic, which ends up changing the inode. Since
    // the users file is bind-mounted into the container filesystem, the changes we
    // make to the file here will not be reflected inside the container if the inode
    // changes, so we manually write to this file non-atomically instead.
    public const USERS_FILE = '/datto/config/local/ownCloudUsers';

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Returns a one dimensional array of all users
     *
     * @return string[] List of all users
     */
    public function get(): array
    {
        $users = [];

        if ($this->filesystem->exists(self::USERS_FILE)) {
            $usersFileContent = @$this->filesystem->fileGetContents(self::USERS_FILE);
            if ($usersFileContent) {
                $users = explode("\n", $usersFileContent);
            }
        }

        return $users;
    }
}
