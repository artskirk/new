<?php

namespace Datto\App\Security\Authenticator;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/**
 * Base class for authenticators.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
abstract class BaseAuthenticator extends AbstractGuardAuthenticator
{
    /**
     * This method is not used. It would normally return the user
     * credentials (i.e. a cookie, or basic auth user/pass), but since
     * we are reusing the 'WebUser' class, this is not necessary.
     *
     * {@inheritdoc}
     */
    public function getCredentials(Request $request)
    {
        return true;
    }

    /**
     * Returns true if the credentials are valid. In our case, this
     * should always return true, because getUser() will throw an
     * exception on failure.
     *
     * {@inheritdoc}
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        return true;
    }

    /**
     * Returning null means that the current request will continue. When this method
     * is called, the authentication should be done already.
     *
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        return null;
    }

    /**
     * Called when there was an error authenticating
     *
     * {@inheritdoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return $this->createNonAuthorizedResponse();
    }

    /**
     * Not used.
     *
     * {@inheritdoc}
     */
    public function supportsRememberMe()
    {
        return false;
    }

    /**
     * Called when there is no authentication information presented.
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        return $this->createNonAuthorizedResponse();
    }

    /**
     * Create appropriate non-authorized response.
     *
     * @return Response
     */
    private function createNonAuthorizedResponse()
    {
        return new Response('Unauthorized request', Response::HTTP_UNAUTHORIZED);
    }
}
