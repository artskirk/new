<?php

namespace Datto\User;

/**
 * Contains user role assignment logic.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class Roles
{
    const ROLE_REMOTE_ADMIN = 'ROLE_REMOTE_ADMIN'; // Same as admin but can delete data
    const ROLE_ADMIN = 'ROLE_ADMIN';
    const ROLE_BASIC_ACCESS = 'ROLE_BASIC_ACCESS';
    const ROLE_NAS_ACCESS = 'ROLE_NAS_ACCESS';
    const ROLE_RESTORE_ACCESS = 'ROLE_RESTORE_ACCESS';
    const ROLE_NO_ACCESS = 'ROLE_NO_ACCESS'; // Needed for login/anonymous pages only!

    /**
     * TODO: document in docs/
     *
     * Internal roles that represent clients of the api. These roles should have the minimum permissions required
     * to do their job.
     *
     *      - ROLE_INTERNAL_CLOUDAPI
     *          Cloudapi is a client that manages assets on the device (eg. replication of assets to cloud devices)
     *
     *      - ROLE_INTERNAL_DTCCOMMANDER
     *          Dtccommander is a client that handles backups for direct-to-cloud agents.
     */
    const ROLE_INTERNAL_CLOUDAPI = 'ROLE_INTERNAL_CLOUDAPI';
    const ROLE_INTERNAL_DTCCOMMANDER = 'ROLE_INTERNAL_DTCCOMMANDER';

    const SUPPORTED_USER_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_BASIC_ACCESS,
        self::ROLE_NAS_ACCESS,
        self::ROLE_RESTORE_ACCESS
    ];

    const HIDDEN_ROLES = [
        self::ROLE_REMOTE_ADMIN,
        self::ROLE_INTERNAL_CLOUDAPI,
        self::ROLE_INTERNAL_DTCCOMMANDER,
        self::ROLE_NO_ACCESS
    ];

    /**
     * Creates a role list with no roles
     *
     * @return string[]
     */
    public static function createEmpty(): array
    {
        return [];
    }

    /**
     * Creates a role list based on the passed in and default roles
     *
     * @param string[] $roles
     * @return string[]
     */
    public static function create(array $roles): array
    {
        if (empty($roles)) { // For backwards compatibility, an empty permissions string makes the user an administrator
            $roles[] = self::ROLE_ADMIN;
        }

        // a user is always given the basic access role
        $roles[] = self::ROLE_BASIC_ACCESS;

        return array_unique($roles);
    }

    public static function upgradeAdminToRemoteAdmin(array $roles): array
    {
        foreach ($roles as &$role) {
            if ($role === Roles::ROLE_ADMIN) {
                $role = Roles::ROLE_REMOTE_ADMIN;
            }
        }
        return $roles;
    }
}
