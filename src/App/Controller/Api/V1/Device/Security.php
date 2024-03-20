<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Security\PasswordService;

/**
 * Contains endpoints for checking password strengths
 *
 * @author Steven Nguyen <snguyen@datto.com>
 */
class Security
{
    /** @var PasswordService */
    private $passwordService;

    public function __construct(
        PasswordService $passwordService
    ) {
        $this->passwordService = $passwordService;
    }

    /**
     * Return strength information about the password
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AUTH_ANONYMOUS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NONE")
     * @param string $username
     * @param string $password
     * @return array
     */
    public function getPasswordStrength(string $password, string $username = ''): array
    {
        $passwordStrength = $this->passwordService->getPasswordStrength($username, $password);
        return $passwordStrength->toArray();
    }
}
