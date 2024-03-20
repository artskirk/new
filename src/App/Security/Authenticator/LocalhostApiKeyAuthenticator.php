<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\Api\ApiKeyService;
use Datto\App\Security\User;
use Datto\Feature\FeatureService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Allows communications from localhost using an API key.
 *
 * @author Philipp Heckel <ph@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class LocalhostApiKeyAuthenticator extends BaseAuthenticator
{
    /** @var FeatureService */
    private $featureService;

    /** @var ApiKeyService */
    private $apiKeyService;

    public function __construct(
        FeatureService $featureService,
        ApiKeyService $apiKeyService
    ) {
        $this->featureService = $featureService;
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Determine if this authenticator is supported.
     *
     * @param Request $request
     * @return bool false to skip this authenticator
     */
    public function supports(Request $request)
    {
        // Check for feature
        if (!$this->featureService->isSupported(FeatureService::FEATURE_AUTH_LOCALHOST_API_KEY)) {
            return false;
        }

        $user = $request->getUser();
        $password = $request->getPassword();

        return isset($_SERVER['REMOTE_ADDR'])
            && isset($_SERVER['SERVER_ADDR'])
            && isset($_SERVER['SERVER_PORT'])
            && $_SERVER['SERVER_ADDR'] === '127.0.0.1'
            && $_SERVER['REMOTE_ADDR'] === '127.0.0.1'
            && isset($user)
            && isset($password);
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
            'apiKey' => $request->getPassword()
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
        if (!isset($credentials['user']) || !isset($credentials['apiKey'])) {
            throw new AuthenticationException();
        }

        $apiKey = $this->apiKeyService->get(
            $credentials['user'],
            $credentials['apiKey']
        );
        if (!$apiKey) {
            throw new AuthenticationException();
        }

        return new User(
            sprintf('%s@LOCAL', $apiKey->getUser()),
            $apiKey->getRoles()
        );
    }
}
