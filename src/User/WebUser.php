<?php

namespace Datto\User;

use Datto\Config\DeviceConfig;
use Datto\Config\Login\LocalLoginService;
use Datto\Log\LoggerAwareTrait;
use Datto\RemoteWeb\RemoteWebService;
use Datto\User\Lockout\LoginLockoutService;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Focuses on users accessing via. the web (local as well as remote).
 * @author Matt Cheman <mcheman@datto.com>
 */
class WebUser implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const REMOTE_LOGIN_VERSION = 2;
    const PUBLIC_KEY = 'remoteLoginKeyV2.pub';

    private DeviceConfig $deviceConfig;
    private WebUserService $webUserService;
    private DateTimeService $dateTimeService;
    private LoginLockoutService $lockoutService;
    private SessionInterface $session;
    private LocalLoginService $localLoginService;
    private RemoteLoginNonceHandler $nonceHandler;
    private string $remoteHost;
    private bool $isSessionLoaded = false;

    /** @var string[] */
    private array $roles;

    private ?string $username;

    public function __construct(
        DeviceConfig $deviceConfig,
        WebUserService $webUserService,
        DateTimeService $dateTimeService,
        LoginLockoutService $lockoutService,
        SessionInterface $session,
        LocalLoginService $localLoginService,
        RemoteLoginNonceHandler $nonceHandler
    ) {
        $this->session = $session;
        $this->deviceConfig = $deviceConfig;
        $this->webUserService = $webUserService;
        $this->lockoutService = $lockoutService;
        $this->remoteHost = RemoteWebService::getRemoteHost();
        $this->dateTimeService = $dateTimeService;
        $this->localLoginService = $localLoginService;
        $this->nonceHandler = $nonceHandler;
        $this->username = null;
    }

    /**
     * Attempts to login the user with the given credentials.
     */
    public function login(string $user, string $pass)
    {
        try {
            $this->checkAuthentication($user, $pass);
            $this->session->set('user', $user);
            $this->session->set('lastActivity', $this->dateTimeService->getTime());
            // regenerating session ID to prevent Session Fixation
            $this->session->migrate();
            $this->logger->info('WEB0001 Successfully logged in user', ['user' => $user, 'host' => $this->remoteHost, 'roles' => $this->getRoles()]); // log code is used by device-web see DWI-2252
        } catch (FailedLoginException $exception) {
            $this->logger->notice('WEB0002 Failed to login user', ['user' => $user, 'host' => $this->remoteHost]); // log code is used by device-web see DWI-2252
            throw $exception;
        }
    }

    /**
     * Handle user authentication for logging in or authenticating with the device
     */
    public function checkAuthentication(string $user, string $password)
    {
        if ($this->lockoutService->isLockedOut($user)) {
            $timeLeft = $this->lockoutService->getTimeLeftInLockout($user);
            throw new FailedLoginException(0, $timeLeft);
        }

        if ($this->webUserService->checkPassword($user, $password)) {
            $this->lockoutService->resetFailedLogins($user);
        } else {
            $this->lockoutService->incrementFailedAttempts($user);

            $attemptsLeft = $this->lockoutService->getRemainingAttempts($user);
            $timeLeft = $this->lockoutService->getTimeLeftInLockout($user);
            throw new FailedLoginException($attemptsLeft, $timeLeft);
        }
    }

    /**
     * Logout the current user
     */
    public function logout()
    {
        $user = $this->session->get('user');
        $this->session->invalidate();
        $this->username = null; // Clear username to ensure we're not logged in for the rest of this request

        $this->logger->info('WEB0003 Logging out user', ['user' => $user, 'host' => $this->remoteHost]);
    }

    /**
     * Returns whether the current user has authenticated and is allowed to access the device.
     */
    public function isValid(): bool
    {
        $this->validateAndLoadSession();
        return !empty($this->username);
    }

    /**
     * @return string|null username
     */
    public function getUserName()
    {
        $this->validateAndLoadSession();
        return $this->username;
    }

    /**
     * Get the roles of the user
     *
     * @return string[] roles
     */
    public function getRoles(): array
    {
        $this->validateAndLoadSession();
        return $this->roles ?? Roles::createEmpty();
    }

    /**
     * @return bool True if the admin role should be upgraded to remote admin
     */
    public function shouldUpgradeAdminRole(): bool
    {
        return $this->session->get('upgradeRoleAdminToRoleRemoteAdmin') === true ||
            // The device config flag allows developers and system tests to bypass the admin restrictions
            // so we don't have to require opening remote web connections for everything.
            $this->deviceConfig->has(DeviceConfig::KEY_FORCE_REMOTE_ADMIN_UPGRADE);
    }

    /**
     * Whether the request supports authentication with cookies.
     * This is true if the remote login params are present since WebUser will attempt to authenticate with those.
     * Also true if the user has logged in previously and has a non-expired session with 'user' set.
     */
    public function requestSupportsCookieAuthenticator(): bool
    {
        return $this->remoteLoginParamsPresent() || $this->session->has('user');
    }

    /**
     * Loads the user based on existing session variables.
     * If the page has the remote login GET params set, it will automatically login with the specified user.
     */
    private function validateAndLoadSession()
    {
        if ($this->isSessionLoaded) {
            return;
        }
        $this->isSessionLoaded = true;

        $this->tryRemoteLogin();

        $this->username = $this->session->get('user');
        if (empty($this->username)) { // no one is logged in
            return;
        }

        // Prevent continuing a session once a user was deleted or disabled
        if (!$this->isRemoteUser($this->username) && !$this->webUserService->isWebAccessEnabled($this->username)) {
            $this->logout();
            return;
        }

        if ($this->isRemoteUser($this->username)) {
            $this->roles = Roles::create([Roles::ROLE_REMOTE_ADMIN]); // remote user always has full access
        } else {
            $this->roles = $this->webUserService->getRoles($this->getUserName() ?? '');
        }

        if ($this->shouldUpgradeAdminRole()) {
            $this->roles = Roles::upgradeAdminToRemoteAdmin($this->roles);
        }

        // After loading the session we save it to release the lock and allow other parallel requests to read/write
        // from it.
        if ($this->session->isStarted()) {
            $this->session->save();
        }
    }

    /**
     * Return whether a user is a remote user.
     */
    public function isRemoteWebUser(): bool
    {
        $username = (string) $this->getUserName();
        return $this->isRemoteUser($username);
    }

    /**
     * @return bool True if we can try to login with the GET parameters present
     */
    private function remoteLoginParamsPresent(): bool
    {
        return isset(
            $_GET['username'],
            $_GET['validUntil'],
            $_GET['loginVersion'],
            $_GET['device'],
            $_GET['nonce'],
            $_GET['signature']
        );
    }

    /**
     * Checks for the presence of remote login parameters and consumes them if present.
     * These parameters are sent to us from AbstractService->getAutoLoginParams() in the portal-team/rly-bundle repo.
     */
    private function tryRemoteLogin()
    {
        if (!$this->remoteLoginParamsPresent()) {
            return;
        }

        $user = $_GET['username'];
        $validUntil = (int)$_GET['validUntil'];
        $version = (int)$_GET['loginVersion'];
        $deviceId = (int)$_GET['device'];
        $nonce = (int)$_GET['nonce'];
        $signature = $_GET['signature'];

        if (!$this->verify($user, $validUntil, $version, $deviceId, $nonce, $signature)) {
            return;
        }

        // Force login disables remote login and forces the partner to login with a valid local user when accessing a
        // device over remote web. Datto employees connecting through the admin portal are not affected by this setting
        // since support needs to be able to access a device without knowing the partner's login.
        $forceLogin = $this->deviceConfig->has(DeviceConfig::KEY_REMOTE_WEB_FORCE_LOGIN) && !$this->isDattoEmployee($user);

        if ($forceLogin) {
            // If valid remote login parameters are present, the user is trusted. This allows us to safely upgrade the
            // role of a user who logs in with a local account (due to force login).
            $this->session->set('upgradeRoleAdminToRoleRemoteAdmin', true);
        } else {
            // Remote login starts a session without the user having to explicitly log in.
            $this->session->set('user', $user);
            $this->session->set('lastActivity', $this->dateTimeService->getTime());
            $this->logger->info('WEB0004 Auto logging in remote user', ['user' => $user, 'host' => $this->remoteHost]);
        }
        // regenerating session ID to prevent Session Fixation
        $this->session->migrate();
    }

    /**
     * Verifies that the passed in parameters were signed with the portal private key and can be trusted.
     * Rejects different versions, expired logins, different devices and nonces that were already used.
     *
     * @param string $user
     * @param int $validUntil
     * @param int $version
     * @param int $deviceId
     * @param int $nonce
     * @param string $signature rawurlencoded(base64_encoded()) openssl signature created on the portal
     * @return bool True if login params are valid, false if not
     */
    private function verify($user, $validUntil, $version, $deviceId, $nonce, $signature): bool
    {
        if ($this->nonceHandler->hasNonceBeenUsed($nonce)) {
            return false;
        }

        $versionValid = $version === self::REMOTE_LOGIN_VERSION;
        $deviceIdValid = $deviceId === intval($this->deviceConfig->get('deviceID'));
        $timeValid = $this->dateTimeService->getTime() <= $validUntil;

        $publicKey = $this->deviceConfig->get(self::PUBLIC_KEY);
        $dataString = "loginVersion=$version&username=$user&validUntil=$validUntil&device=$deviceId&nonce=$nonce";
        $decodedSignature = base64_decode(rawurldecode($signature));
        $signatureValid = openssl_verify($dataString, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;

        if (!$signatureValid) {
            $this->logger->error('WEB0005 Remote login signature failed to validate with passed parameters! This shouldn\'t happen naturally!', ['user' => $user, 'validUntil' => $validUntil, 'version' => $version, 'deviceID' => $deviceId, 'nonce' => $nonce, 'signature' => $signature]);
        }

        if ($versionValid && $deviceIdValid && $signatureValid && $timeValid) {
            $this->nonceHandler->markNonceAsUsed($nonce);
            return true;
        }

        return false;
    }

    /**
     * Return whether a user is a remote user.
     * Remote usernames are formatted like 'matt@DATTO' or 'rachel@PARTNER'
     * while local usernames will not have an '@' in them.
     *
     * @param string $user The username to check
     * @return bool True if the user is remote, false if the user is local
     */
    private function isRemoteUser($user): bool
    {
        return strpos($user, '@') !== false;
    }

    /**
     * Return whether a remote user is a datto employee.
     * The portal-team/rly-bundle repo, which sends us the remote login parameters, adds either @DATTO or @PARTNER to
     * the remote username depending on whether the user is an employee or a partner.
     */
    private function isDattoEmployee($user): bool
    {
        return preg_match('/@DATTO$/', $user) === 1;
    }
}
