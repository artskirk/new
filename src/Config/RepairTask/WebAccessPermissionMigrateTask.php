<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\User\Roles;
use Datto\User\WebAccessUser;
use Datto\User\WebUserService;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;

/**
 * Migrate old permissions to new roles in webaccess file
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class WebAccessPermissionMigrateTask implements ConfigRepairTaskInterface
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var WebUserService */
    private $userService;

    const MAP = [
        // Old old permissions
        'admin' => Roles::ROLE_ADMIN,
        'agent' => Roles::ROLE_BASIC_ACCESS,
        'ajax' => Roles::ROLE_BASIC_ACCESS,
        'backup' => Roles::ROLE_ADMIN,
        'denied' => Roles::ROLE_BASIC_ACCESS,
        'exportimage' => Roles::ROLE_RESTORE_ACCESS,
        'filerestore' => Roles::ROLE_RESTORE_ACCESS,
        'home' => Roles::ROLE_BASIC_ACCESS,
        'index' => Roles::ROLE_BASIC_ACCESS,
        'logout' => Roles::ROLE_BASIC_ACCESS,
        'nas' => Roles::ROLE_NAS_ACCESS,
        'network' => Roles::ROLE_ADMIN,
        'pointlist' => Roles::ROLE_RESTORE_ACCESS,
        'pxebmr' => Roles::ROLE_RESTORE_ACCESS,
        'removeagent' => Roles::ROLE_ADMIN,
        'report' => Roles::ROLE_BASIC_ACCESS,
        'sirisvirtualization' => Roles::ROLE_RESTORE_ACCESS,
        'status' => Roles::ROLE_ADMIN,

        // New old roles
        'Administrator' => Roles::ROLE_ADMIN,
        'Basic' => Roles::ROLE_BASIC_ACCESS,
        'NAS' => Roles::ROLE_NAS_ACCESS,
        'Restore' => Roles::ROLE_RESTORE_ACCESS,
        'None' => Roles::ROLE_NO_ACCESS,
    ];

    /**
     * @param DeviceLoggerInterface $logger
     * @param WebUserService $userService
     */
    public function __construct(DeviceLoggerInterface $logger, WebUserService $userService)
    {
        $this->logger = $logger;
        $this->userService = $userService;
    }

   /**
    * @inheritdoc
    */
    public function run(): bool
    {
        $madeChanges = false;

        $users = $this->userService->getAllUsers();

        foreach ($users as $user) {
            $userChanged = false;
            $newRoles = [];
            $existingRoles = $this->userService->getRoles($user);

            if (empty($existingRoles)) {
                throw new RuntimeException("Expected non-empty roles array for user '$user'");
            }

            foreach ($existingRoles as $existingRole) {
                $existingRole = trim($existingRole);
                if (in_array($existingRole, Roles::SUPPORTED_USER_ROLES)) {
                    // existing supported roles should not change
                    $newRoles[] = $existingRole;
                } elseif (!empty(static::MAP[$existingRole])) {
                    // old permissions should map to new roles
                    $newRoles[] = static::MAP[$existingRole];
                    $userChanged = true;
                }
            }

            if ($userChanged) {
                $newRoles = array_values(array_unique($newRoles));
                $this->userService->setRoles($user, $newRoles);
                $rolesBefore = implode(',', $existingRoles);
                $rolesAfter = implode(',', $newRoles);
                $this->logger->warning('CFG0002 webaccess roles updated for user', [
                    'user' => $user,
                    'before' => $rolesBefore,
                    'after' => $rolesAfter
                ]);
                $madeChanges = true;
            }
        }

        return $madeChanges;
    }
}
