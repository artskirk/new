<?php

namespace Datto\Security;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use RuntimeException;

/**
 * Support for custom Datto Role/Permissions scheme
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class PermissionService
{
    /** @var Filesystem */
    private $filesystem;

    /** @var bool */
    private $initialized;

    /** @var array */
    private $permissions;

    /** @var string */
    private $resourcesDir;

    /**
     * @param string $resourcesDir directory where resource files are stored
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        string $resourcesDir,
        Filesystem $filesystem = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());

        $this->initialized = false;
        $this->permissions = [];
        $this->resourcesDir = $resourcesDir;
    }

    /**
     * Load the role and permission mappings from file.
     *
     * File contains serialized json string with schema:
     * {
     *   "PERMISSION_ONE": ["ROLE_A", "ROLE_B"],
     *   "PERMISSION_TWO": ["ROLE_C"],
     * }
     *
     */
    private function initialize()
    {
        if ($this->initialized) {
            return;
        }

        $filePath = $this->resourcesDir . '/Security/permissions.json';
        $realPath = $this->filesystem->realpath($filePath);
        if ($realPath === false) {
            throw new RuntimeException("Invalid file path '$filePath'");
        }

        $content = $this->filesystem->fileGetContents($realPath);

        if (empty($content)) {
            throw new RuntimeException(
                'Error initializing permissions, file permissions.json is missing or empty.'
            );
        }

        $this->permissions = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Error initializing permissions, could not decode file permissions.json. ' . json_last_error_msg(),
                json_last_error()
            );
        }
        $this->initialized = true;
    }

    /**
     * Return array of string permission names supported by the service.
     *
     * @return string[]
     */
    public function getPermissions(): array
    {
        $this->initialize();
        return array_keys($this->permissions);
    }

    /**
     * Check if the permission is granted for a particular role
     *
     * @param string $permission the permission name being checked
     * @param string $role the role the permission is checked against
     * @return bool true if the permission is granted for the given role
     */
    public function isGrantedForRole(string $permission, string $role): bool
    {
        $this->initialize();

        if (isset($this->permissions[$permission])) {
            $roles = $this->permissions[$permission];
            return is_array($roles) && in_array($role, $roles, true);
        }

        return false;
    }

    /**
     * Returns whether or not the user is granted the permission, based on their roles.
     *
     * @param string $permission the permission to check
     * @param string[] $roles The roles the user has
     * @return bool True if granted, false if not
     */
    public function isGrantedForRoles(string $permission, array $roles)
    {
        $this->initialize();

        foreach ($roles as $role) {
            $isGranted = $this->isGrantedForRole($permission, $role);
            if ($isGranted) {
                return true;
            }
        }
        return false;
    }
}
