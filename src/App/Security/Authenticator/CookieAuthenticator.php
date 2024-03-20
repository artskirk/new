<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\User;
use Datto\Feature\FeatureService;
use Datto\User\WebUser;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Authenticates the Symfony-based pages using the normal login cookie.
 * This is done through the WebUser class.
 *
 * This authenticator only uses a bare minimum of the GuardAuthenticator logic.
 * Instead of retrieving and checking credentials, it simply uses the existing
 * WebUser->isValid() logic to return a user (if logged in), or redirects to the
 * login page (if not logged in).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class CookieAuthenticator extends BaseAuthenticator
{
    // Routes that should not trigger a redirect after login.
    const NONREDIRECTING_ROUTES = [
        'agents_screenshots',
        'translations'
    ];

    /** @var Router */
    private $router;

    /** @var WebUser */
    private $webUser;

    /** @var FeatureService */
    private $featureService;

    /** @var string */
    private $redirectRoute;

    /**
     * @param string $redirectRoute if non empty, request will be redirected to this route url
     * when authentication fails, or no authentication information is provided.
     */
    public function __construct(
        Router $router,
        WebUser $webUser,
        FeatureService $featureService,
        string $redirectRoute = ''
    ) {
        $this->router = $router;
        $this->webUser = $webUser;
        $this->featureService = $featureService;
        $this->redirectRoute = $redirectRoute;
    }

    /**
     * Determine if this authenticator is supported.
     *
     * @param Request $request
     * @return bool false to skip this authenticator
     */
    public function supports(Request $request)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_AUTH_COOKIE)) {
            return false;
        }

        return $this->webUser->requestSupportsCookieAuthenticator();
    }

    /**
     * Return a User object, or throw an exception if the user is not
     * logged in. The returned user object is not related to the WebUser,
     * since we want to keep it clean here.
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        if ($this->webUser->isValid()) {
            return new User((string)$this->webUser->getUserName(), $this->webUser->getRoles());
        } else {
            throw new AuthenticationException();
        }
    }

    /**
     * Called when none of the configured authenticators in security.yaml supports the authentication information
     * presented (such as when a user navigates to the web ui for the first time and doesn't send any authentication
     * information).
     *
     * We use this method to redirect the user to the login page and then back to their desired page once logged in.
     * Note: The entry_point field in security.yaml controls which authenticator's start method will be called.
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $route = $request->attributes->get('_route');
        $isAllowedRoute = !in_array($route, self::NONREDIRECTING_ROUTES);

        if ($request->hasSession() && !$request->isXmlHttpRequest() && $isAllowedRoute) {
            $request->getSession()->set('next', $request->getRequestUri());
        }

        return $this->createNonAuthorizedResponse();
    }

    /**
     * Called when there was an error authenticating
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return $this->start($request, $exception);
    }

    /**
     * Create appropriate response depending on if the authenticator is configured for redirection
     *
     * @return Response
     */
    private function createNonAuthorizedResponse()
    {
        if (!empty($this->redirectRoute)) {
            $url = $this->router->generate($this->redirectRoute);
            return new RedirectResponse($url);
        } else {
            return new Response('Unauthorized request', Response::HTTP_UNAUTHORIZED);
        }
    }
}
