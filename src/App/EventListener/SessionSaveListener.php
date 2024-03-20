<?php

namespace Datto\App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Saves the session and releases the lock on the session file.
 * Releasing the lock prevents other calls with the same session id to be processed in serial due to having
 * to wait for the lock to be released.
 *
 * The session will still be usable, but if you get or set information from the session it will be restarted
 * and the lock will be re-acquired as expected.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class SessionSaveListener implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $session = $event->getRequest()->getSession();

        if ($session->isStarted()) {
            $session->save();
        }
    }

    /**
     * The SessionTimeoutListener is the last listener that reads/writes the session.
     * We want this listener to save the session after all reads and writes happen so we make sure that unless
     * the session is used in a controller the lock is released.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1]
        ];
    }
}
