<?php

namespace Datto\App\Security\Authenticator;

use Datto\Feature\FeatureService;
use Datto\JsonRpc\JsonRpcListener;
use Symfony\Component\HttpFoundation\Request;

/**
 * Perform Anonymous Authentication using method information in the request object.  If the method is allowed to be
 * accessed Anonymously, return a dummy user.  Otherwise skip to the next authenticator.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AnonymousApiAuthenticator extends AnonymousAuthenticator
{
    const ANONYMOUS_JSONRPC_METHODS = array(
        '#^v1/device/register/#',
        '#^v1/device/security/#'
    );

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

        // If this is a JSON RPC request, check for anonymous methods
        $method = $request->attributes->get(JsonRpcListener::ATTR_JSONRPC_METHOD);
        foreach (self::ANONYMOUS_JSONRPC_METHODS as $methodRegex) {
            if (preg_match($methodRegex, $method)) {
                return true;
            }
        }

        return false;
    }
}
