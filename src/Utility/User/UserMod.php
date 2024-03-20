<?php

namespace Datto\Utility\User;

use Datto\Common\Resource\ProcessFactory;

/**
 * Alter Linux users using the standard "usermod" command.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UserMod
{
    /** @var ProcessFactory */
    private $processFactory;

    /** @var OsGroups */
    private $groupsUtility;

    /**
     * @param ProcessFactory|null $processFactory
     * @param OsGroups|null $groupsUtility
     */
    public function __construct(
        ProcessFactory $processFactory = null,
        OsGroups $groupsUtility = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->groupsUtility = $groupsUtility ?? new OsGroups();
    }

    /**
     * Add a user to a group
     *
     * @param string $username
     * @param string $groupname
     */
    public function addToGroup(string $username, string $groupname)
    {
        $commandLine = ['usermod', '-a', '-G', $groupname, $username];
        $process = $this->processFactory->get($commandLine);
        $process->mustRun();
    }

    /**
     * Remove a user from a group
     *
     * @param string $username
     * @param string $groupname
     */
    public function removeFromGroup(string $username, string $groupname)
    {
        $groups = $this->groupsUtility->getGroups($username);
        $groupList = array_filter($groups, function ($entry) use ($groupname) {
            return $entry !== $groupname;
        });

        $commandLine = ['usermod', '-G', implode(',', $groupList), $username];
        $process = $this->processFactory->get($commandLine);
        $process->mustRun();
    }

    /**
     * Disables password login for this user. The user may still be able to log in via other means (e.g. SSH keys).
     */
    public function disablePasswordLogin(string $username): void
    {
        // From https://linux.die.net/man/5/shadow: "If the password field contains some string that is not a valid
        // result of crypt(3), for instance ! or *, the user will not be able to use a unix password to log in."
        // We don't want to use '!' because that prevents RLY jump from working.
        $process = $this->processFactory->get([
            'usermod',
            '-p',
            '*',
            $username
        ])->mustRun();
    }
}
