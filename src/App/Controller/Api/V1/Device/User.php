<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Log\SanitizedException;
use Datto\Security\PasswordService;
use Datto\User\Roles;
use Datto\User\UserService;
use Datto\User\WebUserService;
use Exception;
use Throwable;

/**
 * API endpoint to add and check for device users.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class User
{
    /** @var WebUserService */
    private $webUserService;

    /** @var UserService */
    private $userService;

    /** @var PasswordService */
    private $passwordService;

    public function __construct(
        WebUserService $webUserService,
        UserService $userService,
        PasswordService $passwordService
    ) {
        $this->webUserService = $webUserService;
        $this->userService = $userService;
        $this->passwordService = $passwordService;
    }

    /**
     * Creates a device user (if it does not exist).
     *
     * This method creates a user in the following backends:
     *   (1) Linux (via "useradd" in /etc/passwd and /etc/shadow)
     *   (2) Samba (via pdbedit)
     *   (3) Datto Webaccess file (/datto/config/local/webaccess)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "user" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   }
     * })
     * @param string $user Username
     * @param string $password Password
     * @return bool Always true
     */
    public function create(string $user, string $password)
    {
        try {
            $this->passwordService->validatePassword($password, $user);

            $this->userService->create($user, $password);
            return true;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$password]);
        }
    }

    /**
     * Deletes an existing user.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "user" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   }
     * })
     *
     * @param string $user
     */
    public function delete(string $user): void
    {
        $this->userService->delete($user);
    }

    /**
     * Returns whether or not the device user exists.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "user" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   }
     * })
     * @param string $user Username
     * @return bool True if it exists, false otherwise.
     */
    public function exists(string $user)
    {
        return $this->userService->exists($user);
    }

    /**
     * Set a new password for the user.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "user" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   }
     * })
     * @param string $user
     * @param string $password
     */
    public function setPassword(string $user, string $password): void
    {
        try {
            $this->passwordService->validatePassword($password, $user);

            $this->userService->changePassword($user, $password);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$password]);
        }
    }

    /**
     * Enable or disable the user's access to the web ui
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "username" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   },
     *   "enabled" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     * @param string $username
     * @param bool $enabled
     */
    public function setWebAccess(string $username, bool $enabled): void
    {
        if ($enabled === false && $this->webUserService->isSoleAdministrator($username)) {
            throw new Exception("Cannot disable user. At least one user with the Administrator role must exist.");
        }
        $this->webUserService->setWebAccess($username, $enabled);
    }

    /**
     * Set the roles for the user
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "user" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Length(max=64)
     *   }
     * })
     * @param string $user
     * @param string[] $roles
     */
    public function setRoles(string $user, array $roles): void
    {
        // Fail if the sole admin is attempting to remove admin access from themself
        if ($this->webUserService->isSoleAdministrator($user) && !in_array(Roles::ROLE_ADMIN, $roles)) {
            throw new Exception('Cannot set roles. At least one web admin must exist.');
        }

        $this->webUserService->setRoles($user, Roles::create($roles));
    }
}
