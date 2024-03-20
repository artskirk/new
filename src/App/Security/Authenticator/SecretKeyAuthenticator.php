<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\User;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\User\Roles;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Perform HTTP Basic Authentication using the device's secret key
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class SecretKeyAuthenticator extends BaseAuthenticator
{
    const AUTH_USER = 'secretKey';

    /** @var FeatureService */
    private $featureService;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        FeatureService $featureService,
        DeviceConfig $deviceConfig
    ) {
        $this->featureService = $featureService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Determine if this authenticator is supported.
     *
     * @param Request $request
     * @return bool false to skip this authenticator
     */
    public function supports(Request $request)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_AUTH_SECRET_KEY)) {
            return false;
        }

        return !is_null($request->getUser()) && !is_null($request->getPassword());
    }

    /**
     * Extract the credentials from the request
     *
     * @param Request $request
     * @return array
     */
    public function getCredentials(Request $request)
    {
        return [
            'user' => $request->getUser(),
            'password' => $request->getPassword()
        ];
    }

    /**
     * The returned user object is not related to the WebUser,
     * since we want to keep it clean here.
     *
     * {@inheritdoc}
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $user = $credentials['user'];
        $secretKey = $credentials['password'];

        $isValidUser = hash_equals($user, self::AUTH_USER);
        $isValidSecretKey = hash_equals($secretKey, $this->deviceConfig->get('secretKey'));

        if ($isValidUser && $isValidSecretKey) {
            return new User($user, [Roles::ROLE_ADMIN]);
        } else {
            throw new AuthenticationException();
        }
    }
}
