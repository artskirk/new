<?php

namespace Datto\App\Security\Authenticator;

use Datto\App\Security\User;
use Datto\Feature\FeatureService;
use Datto\User\Roles;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Authenticator used to map API clients using SSL mutual auth
 * to a local role.
 *
 * This authenticator is mainly used for Cloud Siris devices
 * to authorize requests coming from other Datto servers.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ClientSslAuthenticator extends BaseAuthenticator
{
    const EXPECTED_VERIFY_RESULT = 'SUCCESS';
    const CLIENT_CERT_ISSUER_NAME_ROLE_MAP = [
        'root.cloud-api.crt.datto.com' => Roles::ROLE_INTERNAL_CLOUDAPI
    ];

    /**
     * Determine if this authenticator is supported.
     *
     * @param Request $request
     * @return bool false to skip this authenticator
     */
    public function supports(Request $request)
    {
        return isset($_SERVER['SSL_CLIENT_VERIFY'])
            && isset($_SERVER['SSL_CLIENT_I_DN_CN'])
            && isset($_SERVER['SSL_CLIENT_M_SERIAL'])
            && $request->headers->has('X-On-Behalf-Of');
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
            'verifyResult' => $_SERVER['SSL_CLIENT_VERIFY'],
            'clientCertIssuerCommonName' => $_SERVER['SSL_CLIENT_I_DN_CN'],
            'clientCertSerial' => $_SERVER['SSL_CLIENT_M_SERIAL'],
            'onBehalfOfUser' => $request->headers->get('X-On-Behalf-Of')
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
        $verifyResult = $credentials['verifyResult'];
        $clientCertIssuerCommonName = $credentials['clientCertIssuerCommonName'];
        $username = sprintf('%s@SSL-%s', $credentials['onBehalfOfUser'], $credentials['clientCertSerial']);

        // These are some sanity checks that are not strictly necessary since
        // Apache is checking the cert already. However, we're paranoid here
        // and are "pinning" the CA at least.

        if (!hash_equals(self::EXPECTED_VERIFY_RESULT, $verifyResult)) {
            throw new Exception();
        }

        if (!isset(self::CLIENT_CERT_ISSUER_NAME_ROLE_MAP[$clientCertIssuerCommonName])) {
            throw new Exception();
        }

        $role = self::CLIENT_CERT_ISSUER_NAME_ROLE_MAP[$clientCertIssuerCommonName];
        return new User($username, [$role]);
    }
}
