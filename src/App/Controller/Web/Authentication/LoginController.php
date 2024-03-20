<?php

namespace Datto\App\Controller\Web\Authentication;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceState;
use Datto\Config\Login\LocalLoginService;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Service\Registration\RegistrationService;
use Datto\User\FailedLoginException;
use Datto\User\Lockout\LoginLockoutService;
use Datto\User\WebUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the device login page.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class LoginController extends AbstractBaseController
{
    private WebUser $webUser;
    private LocalLoginService $localLoginService;
    private DeviceState $deviceState;

    public function __construct(
        NetworkService $networkService,
        WebUser $webUser,
        LocalLoginService $localLoginService,
        DeviceState $deviceState,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->webUser = $webUser;
        $this->localLoginService = $localLoginService;
        $this->deviceState = $deviceState;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOGIN")
     *
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request): Response
    {
        if ($this->webUser->isValid()) {
            $response = $this->redirectToRoute('homepage');
        } else {
            $parameters = $this->getLoginPageParameters(false);
            $response = $this->render(
                'Authentication/index.login.html.twig',
                $parameters
            );
        }
        return $response;
    }

    /**
     * POST action for logging a user in
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOGIN")
     *
     * @param Request $request
     * @return Response Rendered content or redirection depending on login success
     */
    public function loginAction(Request $request): Response
    {
        $username = (string)$request->request->get('username');
        $password = (string)$request->request->get('password');
        $next = $request->getSession()->get('next') ?? $this->generateUrl('home');

        try {
            $this->webUser->login($username, $password);
            return $this->redirect($next);
        } catch (FailedLoginException $exception) {
            $parameters = $this->getLoginPageParameters(
                true,
                $exception->getAttemptsLeft(),
                $exception->getTimeLeftInLockout()
            );

            return $this->render(
                'Authentication/index.login.html.twig',
                $parameters
            );
        }
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOGIN")
     *
     * @return Response
     */
    public function logoutAction(): Response
    {
        $this->webUser->logout();

        return $this->redirectToRoute('login', ["loggedOut" => true]);
    }

    /**
     * @param bool $loginError
     * @param int $loginAttemptsLeft
     * @param int $timeLeftInLockout
     * @return array
     */
    private function getLoginPageParameters(
        bool $loginError,
        int $loginAttemptsLeft = LoginLockoutService::ATTEMPTS_BEFORE_LOCKOUT,
        int $timeLeftInLockout = 0
    ): array {
        $isRly = RemoteWebService::isRlyRequest();
        $localLoginEnabled = $this->localLoginService->isEnabled();
        $registrationCompleted = $this->deviceState->has(RegistrationService::REGISTRATION_COMPLETED_RECENTLY_KEY);
        if ($registrationCompleted) {
            $this->deviceState->clear(RegistrationService::REGISTRATION_COMPLETED_RECENTLY_KEY);
        }

        return [
            'disableBanners' => true,
            'lockedOut' => $timeLeftInLockout > 0,
            'attemptsLeft' => $loginAttemptsLeft,
            'timeLeftInLockout' => $timeLeftInLockout,
            'loginError' => $loginError,
            'disableLocalLogin' => !$isRly && !$localLoginEnabled,
            'registrationCompleted' => $registrationCompleted
        ];
    }
}
