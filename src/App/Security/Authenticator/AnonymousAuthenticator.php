<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\User;
use Datto\Feature\FeatureService;
use Datto\User\Roles;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Perform Anonymous Authentication using method information in the request object.  If the method is allowed to be
 * accessed Anonymously, return a dummy user.  Otherwise skip to the next authenticator.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class AnonymousAuthenticator extends BaseAuthenticator
{
    // The percent sign is used to make sure that USER_DEVICE is distinguished from any name the user might add locally
    const USER_DEVICE = '%anonymous';
    const USER_ROLE = Roles::ROLE_NO_ACCESS;
    const ANONYMOUS_PATHS = [
        '#^/login#',
        '#^/register#',
        '#^/js/translations/(.+)\.js#'
    ];

    /** @var FeatureService */
    protected $featureService;

    public function __construct(FeatureService $featureService)
    {
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
        if (!$this->featureService->isSupported(FeatureService::FEATURE_AUTH_ANONYMOUS)) {
            return false;
        }

        // If this is a normal web request, check for anonymous paths
        $requestUri = $request->getRequestUri();
        foreach (self::ANONYMOUS_PATHS as $requestUriRegex) {
            if (preg_match($requestUriRegex, $requestUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a User object. If we got here, we know that supports has already verified that this is
     * an anonymous path
     *
     * {@inheritdoc}
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        return new User(self::USER_DEVICE, array(self::USER_ROLE));
    }
}
