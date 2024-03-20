<?php

namespace Datto\User;

use Datto\Samba\SambaManager;
use Datto\Samba\UserService as SambaUserService;
use Exception;

/**
 * Class UserService allows clients to create and check for the existence of users.
 * This class handles users of the following types:
 *  (1) Linux
 *  (2) Web access
 *  (3) Samba
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class UserService
{
    /** @var SambaUserService */
    private $sambaUserService;

    /** @var WebUserService */
    private $webUserService;

    /** @var UnixUserService */
    private $unixUserService;

    /** @var SambaManager */
    private $sambaManager;

    /** @var ShadowUser */
    private $shadowUser;

    public function __construct(
        SambaUserService $userService,
        WebUserService $webUserService,
        UnixUserService $unixUserService,
        SambaManager $sambaManager,
        ShadowUser $shadowUser
    ) {
        $this->sambaUserService = $userService;
        $this->webUserService = $webUserService;
        $this->unixUserService = $unixUserService;
        $this->sambaManager = $sambaManager;
        $this->shadowUser = $shadowUser;
    }

    /**
     * Creates a device user (if it does not exist).
     *
     * This method creates a user in the following backends:
     *   (1) Linux (via "useradd" in /etc/passwd and /etc/shadow)
     *   (2) Samba (via pdbedit)
     *   (3) Datto Webaccess file (/datto/config/local/webaccess)
     *
     * @param string $username Username
     * @param string $password Password
     * @throws Exception If the user already exists
     */
    public function create(string $username, string $password)
    {
        if ($this->shadowUser->exists($username)) {
            throw new Exception('User already exists.', -1001);
        }

        $this->shadowUser->create($username, $password);
        $this->webUserService->create($username, $password);
        $this->sambaUserService->create($username, $password);
    }

    /**
     * Delete a local user if it exists.
     *
     * @param string $username
     * @throws Exception If the user is the last web admin
     */
    public function delete(string $username)
    {
        if ($this->webUserService->isSoleAdministrator($username)) {
            throw new Exception("You must have at least one web admin");
        }

        if ($this->unixUserService->exists($username)) {
            $this->sambaUserService->delete($username);
            $this->sambaManager->removeUserFromAllShares($username);
            $this->sambaManager->sync();

            $this->webUserService->delete($username);
            $this->unixUserService->delete($username);
        }
    }

    /**
     * Returns whether or not the device user exists.
     *
     * @param string $username Username
     * @return bool True if it exists, false otherwise.
     */
    public function exists(string $username): bool
    {
        return $this->shadowUser->exists($username);
    }

    /**
     * Change the password for a user.
     *
     * @param string $username
     * @param string $password
     * @throws Exception If the password change fails or the user or password is invalid.
     */
    public function changePassword(string $username, string $password)
    {
        if (empty($password) || !$this->exists($username)) {
            throw new Exception("Invalid username or password");
        }

        $this->sambaUserService->setPassword($username, $password);
        $this->shadowUser->setUserPass($username, $password);
        $this->webUserService->setPassword($username, $password);
    }

    /**
     * Get all user info for all local users.
     *
     * @return array[] An array of associative arrays with keys username and hasWebAccess
     */
    public function getAll(): array
    {
        $deviceUsers = [];
        $localUsers = $this->sambaUserService->getLocalUsers();
        foreach ($localUsers as $user) {
            $webAccess = $this->webUserService->isWebAccessEnabled($user);
            $deviceUsers[] = [
                'username' => $user,
                'hasWebAccess' => $webAccess,
            ];
        }
        return $deviceUsers;
    }
}
