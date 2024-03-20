<?php

namespace Datto\JsonRpc;

use Datto\Log\LoggerAwareTrait;
use Datto\Log\Processor\ApiLogProcessor;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;
use RuntimeException;

/**
 * Allow JSON RPC requests to be served by symfony controllers
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class JsonRpcListener implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const LOGGER_REQUEST_PREFIX = '<';
    const LOGGER_RESPONSE_PREFIX = '>';

    const JSON_CONTENT_TYPES = [
        'application/json',
        'application/json-rpc',
        'application/x-json'
    ];
    const API_PATH = '/api';
    const LEGACY_API_PATH = '/api/api.php';
    const ATTR_JSONRPC_METHOD = '_jsonrpc_method';
    const ATTR_JSONRPC_ID = '_jsonrpc_id';
    const ATTR_JSONRPC_PATH = '_jsonrpc_handler';
    const ERR_PARSE = -32700;
    const ERR_INVALID_AUTH = -32652;
    const PROFILER_ENDPOINT_DISABLE_LIST = [
        'v1/device/get',
        'v1/device/banners/getall',
        'v1/device/asset/agent/virtualization/getresources',
        'v1/device/asset/agent/virtualization/getcompletevminfo',
        'v1/device/asset/share/externalnas/getbackupstatus',
        'v1/device/asset/share/getstatus',
        'v1/device/asset/agent/getallfiltered',
        'v1/device/asset/recoverypoints/getallbyasset',
        'v1/device/asset/recoverypoints/getalllocalrecoverypointinfo'
    ];
    const LOGGING_ENDPOINT_EXCLUSIONS = [
        'v1/device/get', // called too frequently
        'v1/device/banners/getall', // called too frequently
        'v1/device/restore/iscsi/find', // response contains 30MB+ of text
        'v1/device/asset/agent/get', // large payload
        'v1/device/asset/agent/getallfiltered', // large payload
        'v1/device/asset/getlogs', // causing infinite logging loop BCDR-20540
        'v1/device/asset/recoverypoints/getallbyasset', // large payload
        'v1/device/asset/recoverypoints/getalllocalrecoverypointinfo', // large payload
        'v1/device/bmr/failures/record', // large payload
        'v1/device/getsupportzip' // large payload
    ];

    /** @var Profiler */
    private $profiler;

    public function __construct(Profiler $profiler = null)
    {
        $this->profiler = $profiler; // profiler is null in prod mode
    }

    /**
     * Inspect the request, if it is a JSON RPC request, validate, extract method for router, and params for controller
     */
    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        $path = strtolower($request->getPathInfo());

        if (!self::isApiPath($path)) {
            return;
        }

        try {
            $msg = $this->extractRpcMessage($request);
        } catch (Throwable $e) {
            $msg = self::formatErrorMessage($e->getCode(), $e->getMessage());
            $event->setResponse(new JsonResponse($msg));
            return;
        }

        // store rpc method data, will be used by router
        $method = strtolower($msg['method']);
        $request->attributes->set(self::ATTR_JSONRPC_PATH, self::API_PATH);
        $request->attributes->set(self::ATTR_JSONRPC_METHOD, $method);

        // id is optional
        if (!empty($msg['id'])) {
            $request->attributes->set(self::ATTR_JSONRPC_ID, $msg['id']);
        }

        if (!empty($msg['params'])) {
            $request->request->replace($msg['params']);
        }

        $this->handleProfiler($method);

        // Log request (or not if excluded)
        if (!in_array($method, self::LOGGING_ENDPOINT_EXCLUSIONS)) {
            $requestMsg = $request->getContent();

            if ($requestMsg) {
                $this->logger->debug('API0001 <', [ApiLogProcessor::JSON_MESSAGE_TOKEN => $requestMsg]);
            }
        }

        // Some libraries try to write to output, buffer so we can throw it away later
        ob_start();
    }

    /**
     * Get result returned by JSON RPC controller and create proper JSON RPC response
     */
    public function onKernelView(ViewEvent $event)
    {
        if (!static::isJsonRpcRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        $id = $request->attributes->has(self::ATTR_JSONRPC_ID);

        if (empty($id)) {
            // json rpc notifications have no response body
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));
            return;
        }

        $result = $event->getControllerResult();

        $id = $request->attributes->get(self::ATTR_JSONRPC_ID);
        $msg = self::formatSuccessMessage($id, $result);
        $event->setResponse(new JsonResponse($msg));
    }

    /**
     * Catch exceptions and format JSON RPC error response
     */
    public function onKernelException(ExceptionEvent $event)
    {
        if (!static::isJsonRpcRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $id = $request->attributes->get(self::ATTR_JSONRPC_ID);

        if (empty($id)) {
            // json rpc notifications get exceptions suppressed
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));
            $event->allowCustomResponseCode();
            return;
        }

        $exception = $event->getThrowable();

        // json rpc queries get an exception response.
        if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedHttpException) {
            $msg = self::formatInvalidAuthMessage($id);
            $statusCode = Response::HTTP_UNAUTHORIZED;
            $this->logger->error('API0006 Unauthorized request', [ApiLogProcessor::JSON_MESSAGE_TOKEN => $request->getContent(), 'error' => $exception->getMessage()]);
        } else {
            $msg = self::formatErrorMessage($exception->getCode(), $exception->getMessage(), $id);
            $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            $this->logger->error('API0005 Error handling request', [ApiLogProcessor::JSON_MESSAGE_TOKEN => $request->getContent(), 'error' => $exception->getMessage()]);
        }

        $event->setResponse(new JsonResponse($msg, $statusCode));
        $event->allowCustomResponseCode();
    }

    /**
     * Evaluate responses and transform to proper JSON RPC message as necessary
     */
    public function onKernelResponse(ResponseEvent $event)
    {
        if (!static::isJsonRpcRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $requestMsg = $request->getContent();

        // throw away anything written to output buffer
        if (ob_get_level() > 0) {
            $bufferedOutput = ob_get_clean();
            if (!empty($bufferedOutput)) {
                $this->logger->warning('API0004 Buffered output discarded', [ApiLogProcessor::JSON_MESSAGE_TOKEN => $requestMsg, 'bufferedOutput' => $bufferedOutput]);
            }
        }

        $response = $event->getResponse();
        $id = $request->attributes->get(self::ATTR_JSONRPC_ID);
        $method = $request->attributes->get(self::ATTR_JSONRPC_METHOD);

        //  Unauthorized code should be returned as a specific JSON RPC message
        $isUnauthorizedResponse = $response->getStatusCode() === Response::HTTP_UNAUTHORIZED;

        if (!empty($id) && $isUnauthorizedResponse) {
            $msg = self::formatInvalidAuthMessage($id);
            $event->setResponse(new JsonResponse($msg, Response::HTTP_UNAUTHORIZED));
        }

        // Log response (or not if excluded)
        if (!in_array($method, self::LOGGING_ENDPOINT_EXCLUSIONS)) {
            $responseMsg = $response->getContent();

            if ($responseMsg) {
                $this->logger->debug('API0002 >', [ApiLogProcessor::JSON_MESSAGE_TOKEN => $responseMsg]);
            } else {
                $this->logger->debug('API0003 > (Completed notification. No reply.)');
            }
        }
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 32]],
            KernelEvents::VIEW => 'onKernelView',
            KernelEvents::EXCEPTION => 'onKernelException',
            KernelEvents::RESPONSE => 'onKernelResponse'
        ];
    }

    /**
     * Returns whether or not the request path is for an API endpoint or not.
     *
     * @param string $path
     * @return bool
     */
    public static function isApiPath(string $path): bool
    {
        return $path === self::API_PATH || $path === self::LEGACY_API_PATH;
    }

    /**
     * Extract JSON RPC message from the request
     *
     * @param Request $request
     * @return array an error response that should be returned immediately to the client
     */
    private function extractRpcMessage(Request $request): array
    {
        if (!$this->isJsonContentType($request)) {
            throw new RuntimeException('Unsupported Media Type', 415);
        }

        $content = $request->getContent();
        if (empty($content)) {
            throw new RuntimeException('Bad Request', 400);
        }

        $msg = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Parse error', static::ERR_PARSE);
        }

        if (empty($msg['jsonrpc']) || $msg['jsonrpc'] !== '2.0') {
            throw new JsonRpcInvalidRequestException();
        }

        if (empty($msg['method'])) {
            throw new JsonRpcInvalidRequestException();
        }

        // The 'params' key is optional, but must be non-null when provided
        if (array_key_exists('params', $msg) && !is_array($msg['params'])) {
            throw new JsonRpcInvalidRequestException();
        }

        // The presence of the 'id' key indicates that a response is expected
        if (array_key_exists('id', $msg)) {
            $id = $msg['id'];

            if (!is_int($id) && !is_float($id) && !is_string($id) && ($id !== null)) {
                throw new JsonRpcInvalidRequestException();
            }
        }

        return $msg;
    }

    /**
     * Check if this request contains an appropriate json content-type.
     *
     * @param Request $request
     * @return bool
     */
    private function isJsonContentType(Request $request)
    {
        $contentType = $request->headers->get('CONTENT_TYPE');

        return in_array($contentType, self::JSON_CONTENT_TYPES);
    }

    /**
     * Returns error object indicating error authorizing request
     *
     * @param string $id
     * @return array
     */
    private static function formatInvalidAuthMessage(string $id): array
    {
        return self::formatErrorMessage(self::ERR_INVALID_AUTH, 'Invalid auth.', $id);
    }

    /**
     * Returns a properly-formatted error object.
     *
     * @param int $code
     * Integer value representing the general type of error encountered.
     *
     * @param string $message
     * Concise description of the error (ideally a single sentence).
     *
     * @param mixed $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @return array
     * Returns an error object.
     */
    private static function formatErrorMessage(int $code, string $message, string $id = null): array
    {
        return [
            'id' => $id,
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }

    /**
     * Returns a properly-formatted response object.
     *
     * @param mixed $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @param mixed $result
     * Return value from the server method, which will now be delivered to the user.
     *
     * @return array
     * Returns a response object.
     */
    private static function formatSuccessMessage(string $id, $result = null)
    {
        return [
            'id' => $id,
            'jsonrpc' => '2.0',
            'result' => $result
        ];
    }

    private static function isJsonRpcRequest(KernelEvent $event): bool
    {
        return $event->getRequest()->attributes->has(self::ATTR_JSONRPC_METHOD);
    }

    /**
     * Disable the profiler for excluded api endpoints
     * These endpoints are called too often and fill up the device storage with the profiler cache
     *
     * @param string $method the api method
     */
    private function handleProfiler(string $method)
    {
        if ($this->profiler !== null && in_array($method, self::PROFILER_ENDPOINT_DISABLE_LIST)) {
            $this->profiler->disable();
        }
    }
}
