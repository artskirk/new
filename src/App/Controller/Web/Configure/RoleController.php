<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\User\Roles;
use Datto\User\WebUserService;
use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class RoleController extends AbstractBaseController
{
    private WebUserService $webUserService;

    public function __construct(
        NetworkService $networkService,
        WebUserService $webUserService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->webUserService = $webUserService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_USER_WRITE")
     *
     * @param string $user
     * @return Response
     */
    public function indexAction(string $user): Response
    {
        if (!$this->webUserService->exists($user)) {
            throw new Exception("User does not exist.");
        }

        $userRoles = array_flip($this->webUserService->getRoles($user));
        $isSoleAdministrator = $this->webUserService->isSoleAdministrator($user);

        foreach (Roles::SUPPORTED_USER_ROLES as $role) {
            $isBasicAccessRole = $role === Roles::ROLE_BASIC_ACCESS;
            $isSoleAdministratorRole = $isSoleAdministrator && $role === Roles::ROLE_ADMIN;
            $roles[] = [
                'name' => $role,
                'enabled' => isset($userRoles[$role]),
                'unchangeable' => $isBasicAccessRole || $isSoleAdministratorRole
            ];
        }

        return $this->render(
            'Configure/Roles/index.html.twig',
            [
                'user' => $user,
                'roles' => $roles ?? []
            ]
        );
    }
}
