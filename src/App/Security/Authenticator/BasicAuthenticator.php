<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\User;
use Datto\Feature\FeatureService;
use Datto\User\Roles;
use Datto\User\WebUser;
use Datto\User\WebUserService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Perform HTTP Basic Authentication using user/pass in web access file
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class BasicAuthenticator extends BaseAuthenticator
{
    /** @var WebUserService */
    private $webUserService;

    /** @var WebUser */
    private $webUser;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        WebUserService $webUserService,
        WebUser $webUser,
        FeatureService $featureService
    ) {
        $this->webUserService = $webUserService;
        $this->webUser = $webUser;
        $this->featureService = $featureService;
    }

    /**
     * Determine if this authenticator is supported.
     *
     * @param Request $request
     * @return bool false to skip this authenticator
     */
    public function supports(Request $request)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_AUTH_BASIC)) {
            return false;
        }

        return !is_null($request->getUser()) && !is_null($request->getPassword());
    }

    /**
     * Extract the credentials from the request, they will be passed to getUser
     *
     * @param Request $request
     * @return array|mixed
     */
    public function getCredentials(Request $request)
    {
        return [
            'user' => $request->getUser(),
            'password' => $request->getPassword()
        ];
    }

    /**
     * Return a User object, or throw an exception if the user is not
     * logged in. The returned user object is not related to the WebUser,
     * since we want to keep it clean here.
     *
     * {@inheritdoc}
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $user = $credentials['user'];
        $password = $credentials['password'];

        $this->webUser->checkAuthentication($user, $password);

        $roles = Roles::create($this->webUserService->getRoles($user));
        if ($this->webUser->shouldUpgradeAdminRole()) {
            $roles = Roles::upgradeAdminToRemoteAdmin($roles);
        }

        return new User($user, $roles);
    }
}
