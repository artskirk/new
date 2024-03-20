<?php

namespace Datto\App\EventListener;

use Datto\App\Controller\Web\Exception\ExceptionController;
use Datto\App\Security\RequiresFeature;
use Datto\App\Security\RequiresPermission;
use Datto\Log\DeviceLoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Controller\ErrorController;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Listener to ensure that every controller (API and web) has at least
 * one @RequiresFeature and one @RequiresPermission annotation.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class AuthorizationListener implements EventSubscriberInterface
{
    /**
     * The following controllers are called internally and are exempt from
     * annotation checking. If annotations exist, or if the controller is
     * a Symfony controller, the checking of those annotations will fail
     * and an improper response will be returned.
     */
    const ANNOTATION_EXEMPT_CONTROLLERS = [
        ExceptionController::class,
        ErrorController::class,
        RedirectController::class
    ];

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    public function __construct(
        DeviceLoggerInterface $logger,
        TokenStorageInterface $tokenStorage
    ) {
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event)
    {
        if (is_null($this->tokenStorage->getToken())) {
            return;
        }

        $controller = $event->getController();
        $eventControllerClass = '';
        if (is_object($controller)) { // to handle ErrorController which uses  __invoke() instead of a passed method
            $eventControllerClass = get_class($controller);
        } elseif (is_array($controller) && isset($controller[0])) {
            $eventControllerClass = get_class($controller[0]);
        }

        if (in_array($eventControllerClass, self::ANNOTATION_EXEMPT_CONTROLLERS)) {
            return;
        }

        $request = $event->getRequest();

        $grantedConfiguration = new IsGranted([]);
        $attributeKey = "_" . $grantedConfiguration->getAliasName();

        // See if there are already IsGranted annotations on this controller method
        $configurations = $request->attributes->get($attributeKey, []);

        $requiresPermissionSet = false;
        $requiresFeatureSet = false;

        foreach ($configurations as $configuration) {
            if ($configuration instanceof RequiresPermission) {
                $requiresPermissionSet = true;
            }

            if ($configuration instanceof RequiresFeature) {
                $requiresFeatureSet = true;
            }
        }

        if (!$requiresPermissionSet || !$requiresFeatureSet) {
            $this->logger->error('AUT0001 Controller does not have required permission or feature annotation. Fatal failure.', ['controller' => $request->getRequestUri()]);
            throw new AuthenticationException();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelControllerArguments'],
        ];
    }
}
