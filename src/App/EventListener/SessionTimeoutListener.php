<?php

namespace Datto\App\EventListener;

use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * This Listener implements the logic for idle session timeout.
 * @author Sachin Shetty <sshetty@datto.com>
 */
class SessionTimeoutListener implements EventSubscriberInterface
{
    const TIMEOUT_LIMIT_SECONDS = 21600; //6 hours idle session timeout

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(DateTimeService $dateTimeService, DeviceLoggerInterface $logger)
    {
        $this->dateTimeService = $dateTimeService;
        $this->logger = $logger;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->getRequest()->hasSession()) {
            $session = $event->getRequest()->getSession();
            if ($this->isSessionTimeout($session)) {
                $this->logger->info("STL0001 Max idle session timeout limit of 6 hours reached; logging out user.");
                $session->invalidate();
            }
        }
    }

    public function isSessionTimeout(SessionInterface $session): bool
    {
        $currentTime = $this->dateTimeService->getTime();
        $lastActivityTime = $session->get('lastActivity') ?? $currentTime;
        return (($currentTime - $lastActivityTime) > static::TIMEOUT_LIMIT_SECONDS);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 5]],
        ];
    }
}
