<?php

namespace Datto\App;

use Datto\JsonRpc\JsonRpcListener;
use Datto\RemoteWeb\RemoteWebService;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * We override the Request object so we can make it handle JsonRpc requests and redirecting
 * to the correct url when using rly.
 *
 * Symfony can get confused when requests come over rly.
 * This is because rly terminates the https connection and proxies an http request over ssh to the localhost network on
 * the device. Symfony sees requests from 127.0.0.1 and if it handles a redirect, it will return the incorrect host
 * and scheme.
 * For example:
 *     http://127.0.0.1/agents instead of https://devicehostname-6zdtvbw7skvb3i53.dattoweb.com/agents
 * The user's browser cannot directly access http://127.0.0.1/agents so we must make sure the redirect uses the rly
 * supplied host and https as the scheme.
 *
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class Request extends SymfonyRequest
{
    public function __construct(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        // By setting 127.0.0.1 as a trusted proxy, we tell symfony it's okay to use the X_FORWARDED_HOST header as the
        // host instead of 127.0.0.1. This header is supplied by rly.
        // For example: devicehostname-6zdtvbw7skvb3i53.dattoweb.com
        // This ensures that we return the correct url for redirects over rly.
        if (RemoteWebService::isRlyRequest()) {
            self::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        }
    }

    public function isSecure(): bool
    {
        // When https requests come over rly, symfony sees them coming over http from 127.0.0.1. We must manually return
        // true here so that redirects will return urls with the https scheme instead of http.
        if (RemoteWebService::isRlyRequest()) {
            return true;
        }

        return parent::isSecure();
    }

    /**
     * Handle transforming json rpc methods into valid routes
     */
    public function getPathInfo(): string
    {
        $jsonRpcPath = $this->attributes->get(JsonRpcListener::ATTR_JSONRPC_PATH);
        $jsonRpcMethod = $this->attributes->get(JsonRpcListener::ATTR_JSONRPC_METHOD);

        // All JsonRpc requests hit the /api endpoint, however the routes are laid out as paths internally.
        // For example the /api/v1/device/system/gettimezone route corresponds to $jsonRpcPath = '/api' and
        // $jsonRpcMethod = 'v1/device/system/gettimezone'. We concatenate them together here to make it match the route
        if (!empty($jsonRpcPath) && !empty($jsonRpcMethod)) {
            return $jsonRpcPath . '/' . $jsonRpcMethod;
        }

        return parent::getPathInfo();
    }
}
