<?php

namespace Datto\App\EventListener;

use Datto\Https\HttpsService;
use Datto\Service\Registration\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RedirectListener implements EventSubscriberInterface
{
    const REGISTRATION_ROUTE = 'registration';
    const LOGIN_ROUTE = 'login';
    const PROFILER_ROUTE = '_profiler';
    const PROFILER_WDT_ROUTE = '_wdt';
    const TRANSLATIONS_ROUTE = 'translations';

    const IGNORED_ROUTES = [
        self::PROFILER_WDT_ROUTE,
        self::PROFILER_ROUTE,
        self::TRANSLATIONS_ROUTE
    ];

    /** @var Router */
    private $router;

    /** @var HttpsService */
    private $httpsService;

    /** @var RegistrationService */
    private $registrationService;

    public function __construct(
        Router $router,
        HttpsService $httpsService,
        RegistrationService $registrationService
    ) {
        $this->router = $router;
        $this->httpsService = $httpsService;
        $this->registrationService = $registrationService;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Prevent redirects for API requests
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if ($request->isXmlHttpRequest() || $request->getMethod() !== 'GET') {
            return;
        }

        $this->redirectIfHttp($event, $request);

        // Prevent redirects for the symfony profiler and translations controller
        if (!in_array($route, self::IGNORED_ROUTES)) {
            $this->redirectRegistrationIfRequired($event, $request);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 12]],
        ];
    }

    /**
     * Handle redirecting to HTTPS if auto-redirect is enabled. The function has
     * awkwardly nested if-statements to improve performance.
     *
     * @param RequestEvent $event
     * @param Request $request
     */
    private function redirectIfHttp(RequestEvent $event, Request $request): void
    {
        if (!$request->isSecure()) {
            if ($this->httpsService->isRedirectEnabled()) {
                $redirectHost = $this->httpsService->getRedirectHost();
                $hasRedirectHost = $redirectHost !== false;
                $allowsRedirects = $this->httpsService->allowRedirectToPrimaryNetworkInterface();
                $likelyViaProxy = $request->getClientIp() === '127.0.0.1';

                $canRedirect = $hasRedirectHost && $allowsRedirects && !$likelyViaProxy;

                if ($canRedirect) {
                    $redirectUrl = sprintf('https://%s%s', $redirectHost, $request->getRequestUri());
                    $response = new RedirectResponse($redirectUrl);
                    $event->setResponse($response);
                }
            }
        }
    }

    /**
     * Handle redirecting to the registration page if the device is not yet registered.
     */
    private function redirectRegistrationIfRequired(RequestEvent $event, Request $request): void
    {
        $requiresRegistration = !$this->registrationService->isRegistered();
        $isRedirectableRoute = $request->attributes->get('_route') !== self::REGISTRATION_ROUTE;

        if ($requiresRegistration && $isRedirectableRoute) {
            $this->redirectToRoute($event, self::REGISTRATION_ROUTE);
        }
    }

    /**
     * Helper method for redirecting to a route by name.
     */
    private function redirectToRoute(RequestEvent $event, string $routeName): void
    {
        $url = $this->router->generate($routeName);
        $response = new RedirectResponse($url);
        $event->setResponse($response);
    }
}
