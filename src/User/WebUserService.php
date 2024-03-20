<?php

namespace Datto\User;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\LocalConfig;
use Exception;
use Datto\Common\Utility\Filesystem;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class WebUserService
{
    /** @var LocalConfig */
    private $localConfig;

    /** @var WebAccessUser[]|null */
    private $webAccessUsers;

    /** @var WebAccessFileRepositoryFactory */
    private $webAccessFileRepositoryFactory;

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param LocalConfig|null $localConfig
     * @param WebAccessFileRepositoryFactory|null $webAccesFileRepositoryFactory
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        LocalConfig $localConfig = null,
        WebAccessFileRepositoryFactory $webAccesFileRepositoryFactory = null,
        Filesystem $filesystem = null
    ) {
        $this->localConfig = $localConfig ?: new LocalConfig();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->webAccessFileRepositoryFactory = $webAccesFileRepositoryFactory ?: new WebAccessFileRepositoryFactory($this->filesystem);
    }

    /**
     * Adds a web user to the device.
     * Web users are stored in the local config webaccess key file.
     *
     * @param string $user User
     * @param string $password Password
     */
    public function create(string $user, string $password)
    {
        if ($user !== '%recovery') {
            ShadowUser::validateName($user);
        }

        $enabled = true;
        $newUser = new WebAccessUser($enabled, $user, '', '');
        $newUser->setPassword($password);

        $users = $this->getWebAccessUsers();
        $users[$user] = $newUser;

        $this->saveWebAccessUsers($users);
    }

    /**
     * Returns whether the user exists
     *
     * @param string $user The username
     * @return bool True if the user exists, false otherwise
     */
    public function exists(string $user): bool
    {
        $users = $this->getWebAccessUsers();
        return isset($users[$user]);
    }

    /**
     * Deletes a user.
     *
     * @param string $user The username to delete
     */
    public function delete(string $user)
    {
        if (!$this->exists($user)) {
            return;
        }
        $users = $this->getWebAccessUsers();
        unset($users[$user]);

        if (!$this->saveWebAccessUsers($users)) {
            throw new Exception("Cannot delete user. At least one enabled user must exist.");
        }
    }

    /**
     * Returns whether the user can log in to the web ui
     *
     * @param string $user The username to check
     * @return bool True if the user is enabled, false if disabled
     */
    public function isWebAccessEnabled(string $user): bool
    {
        if ($this->exists($user)) {
            $users = $this->getWebAccessUsers();
            return $users[$user]->isEnabled();
        }
        return false;
    }

    /**
     * Enables or disables logging in via the web ui for a user
     *
     * @param string $user The username to change
     * @param bool $enabled True to enable the user, false to disable it
     */
    public function setWebAccess(string $user, bool $enabled)
    {
        if (!$this->exists($user)) {
            throw new Exception("Cannot set web access. User does not exist.");
        }

        $users = $this->getWebAccessUsers();
        $users[$user]->setEnabled($enabled);

        if (!$this->saveWebAccessUsers($users)) {
            throw new Exception("Cannot disable user. At least one user must always be enabled.");
        }
    }

    /**
     * Changes the password of a user
     *
     * @param string $user The username
     * @param string $newPassword The password to change
     */
    public function setPassword(string $user, string $newPassword)
    {
        if (!$this->exists($user)) {
            throw new Exception("Cannot set password. User does not exist.");
        }

        $users = $this->getWebAccessUsers();
        $users[$user]->setPassword($newPassword);

        $this->saveWebAccessUsers($users);
    }

    /**
     * Returns whether the user has a valid username/password pair that is enabled
     * This will also rehash the user's password if the current hash is insecure.
     *
     * @param string $user The username to check
     * @param string $password The password to check
     * @return bool True if the user and password are valid and web access is enabled, false if not
     */
    public function checkPassword(string $user, string $password): bool
    {
        if (!$this->exists($user)) {
            return false;
        }

        $users = $this->getWebAccessUsers();

        if ($users[$user]->checkPassword($password)) {
            if ($users[$user]->passwordNeedsRehash()) {
                $users[$user]->setPassword($password);
                $this->saveWebAccessUsers($users);
            }
            return $users[$user]->isEnabled();
        }

        return false;
    }

    /**
     * Return the roles of the user
     *
     * @param string $user The username
     * @return string[] The roles the user belongs to
     */
    public function getRoles(string $user): array
    {
        if (!$this->exists($user)) {
            return Roles::createEmpty(); // empty roles for nonexistant user
        }

        $users = $this->getWebAccessUsers();
        $roles = trim($users[$user]->getRoles());

        $rolesArray = empty($roles) ? [] : explode(',', $roles);

        return Roles::create($rolesArray);
    }

    /**
     * Changes the roles of a user
     *
     * @param string $user
     * @param string[] $roles
     */
    public function setRoles(string $user, array $roles)
    {
        if (!$this->exists($user)) {
            throw new Exception("Cannot set roles. User does not exist.");
        }

        $validatedRoles = array_intersect(Roles::SUPPORTED_USER_ROLES, $roles);

        $roles = implode(',', $validatedRoles);

        $users = $this->getWebAccessUsers();
        $users[$user]->setRoles($roles);

        $this->saveWebAccessUsers($users);
    }

    /**
     * Get the list of users on the device
     *
     * @return string[] Array of usernames
     */
    public function getAllUsers(): array
    {
        return array_keys($this->getWebAccessUsers());
    }

    /**
     * Get the list of users on the device with a particular role
     *
     * @param string $role
     * @return string[] Array of usernames
     */
    public function getEnabledUsersWithRole(string $role): array
    {
        $roleUsers = [];
        $webAccessUsers = $this->getAllUsers();

        foreach ($webAccessUsers as $user) {
            $roles = $this->getRoles($user);
            if (in_array($role, $roles) && $this->isWebAccessEnabled($user)) {
                $roleUsers[] = $user;
            }
        }

        return $roleUsers;
    }

    /**
     * Returns true if only one user with the Administrator role exists
     *
     * @param string $username
     * @return bool
     */
    public function isSoleAdministrator(string $username): bool
    {
        $adminUsers = $this->getEnabledUsersWithRole(Roles::ROLE_ADMIN);
        return count($adminUsers) === 1 && $adminUsers[0] === $username;
    }

    /**
     * Gets the list of web users from the webaccess keyfile.
     *
     * @return WebAccessUser[]
     */
    private function getWebAccessUsers(): array
    {
        if ($this->webAccessUsers) {
            return $this->webAccessUsers;
        }

        $userLines = explode("\n", trim($this->localConfig->get('webaccess')));
        $users = [];

        foreach ($userLines as $line) {
            if (preg_match("/^(#?)([^:]+):([^:]*):([^:]*)$/", $line, $matches)) {
                $enabled = $matches[1] !== '#';
                $username = $matches[2];
                $passwordHash = $matches[3];
                $roles = $matches[4];

                // only return the first entry in case of duplicates
                $users[$username] = $users[$username] ?? new WebAccessUser(
                    $enabled,
                    $username,
                    $passwordHash,
                    $roles
                );
            }
        }

        $this->webAccessUsers = $users;
        return $users;
    }

    /**
     * Save the users to the webaccess keyfile
     *
     * @param WebAccessUser[] $users The users to save
     * @return bool True if saved, false if not
     */
    private function saveWebAccessUsers(array $users): bool
    {
        $userLines = [];
        $enabledUserExists = false;
        foreach ($users as $user) {
            $enabled = $user->isEnabled() ? '' : '#';
            $username = $user->getUsername();
            $passwordHash = $user->getPasswordHash();
            $roles = $user->getRoles();

            if ($user->isEnabled()) {
                $enabledUserExists = true;
            }

            $userLines[] = "$enabled$username:$passwordHash:$roles";
        }

        if (!$enabledUserExists) {
            return false; // don't save when there isn't an enabled user
        }

        $this->localConfig->set('webaccess', implode("\n", $userLines));
        $this->webAccessUsers = $users;
        return true;
    }

    /**
     * Adds an imported webaccess user and updates the user password from imported version of sthe file
     *
     * @param string $userName The /etc/passwd file to import
     * @param string $importWebAccessFile The webacess file containing user to import
     */
    public function addUserImportPassword(string $userName, string $importWebAccessFile)
    {
        $importWebAccessFileRepository = $this->webAccessFileRepositoryFactory->createFileRepository($importWebAccessFile);
        $importWebAccessFileRepository->load();
        $importWebAccessEntry = $importWebAccessFileRepository->getByName($userName);
        $webAccessFileRepository = $this->webAccessFileRepositoryFactory->createSystemRepository();
        $webAccessFileRepository->load();
        $webAccessFileRepository->add($importWebAccessEntry);
        $webAccessFileRepository->save();
    }
}
