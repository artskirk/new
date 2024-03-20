<?php

namespace Datto\App\Security;

use Datto\JsonRpc\JsonRpcListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Listener that validates the CSRF token on every unsafe HTTP method.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class CsrfValidationListener implements EventSubscriberInterface
{
    const SAFE_HTTP_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    const CSRF_TOKEN_ID = 'csrf';
    const CSRF_HTTP_HEADER = 'X-Csrf-Token';

    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    /** @var string */
    private $sessionIdCookieName;

    public function __construct(CsrfTokenManagerInterface $csrfTokenManager, string $sessionIdCookieName)
    {
        $this->csrfTokenManager = $csrfTokenManager;
        $this->sessionIdCookieName = $sessionIdCookieName;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = strtolower($request->getPathInfo());

        if (!$request->cookies->has($this->sessionIdCookieName)) {
            //Don't verify CSRF for requests authenticated without session.
            return;
        }

        if (in_array($request->getMethod(), self::SAFE_HTTP_METHODS)) {
            //Don't verify CSRF for safe methods.
            return;
        }

        if (JsonRpcListener::isApiPath($path)) {
            //Verify CSRF for API request.
            $receivedCsrf = $request->headers->get(self::CSRF_HTTP_HEADER);
        } else {
            //Verify CSRF for regular unsafe request
            $receivedCsrf = $request->request->get(self::CSRF_TOKEN_ID);
        }


        if (!is_string($receivedCsrf)) {
            $receivedCsrf = null;
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $receivedCsrf))) {
            $event->setResponse(new Response("INVALID CSRF TOKEN", 403));
        }
    }

    /**
     * It's supposed to happen after Symfony\Component\HttpKernel\EventListener\SessionListener.
     *
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 64]
        ];
    }
}
