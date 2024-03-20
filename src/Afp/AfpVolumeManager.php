<?php

namespace Datto\Afp;

use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Network\Zeroconf\Avahi;
use Datto\Utility\Systemd\Systemctl;
use Exception;

/**
 * Manage volumes for AFP shares.
 * @author Peter Geer <pgeer@datto.com>
 */
class AfpVolumeManager
{
    public const AFP_CONF_FILE = '/etc/netatalk/afp.conf';
    public const UAM_LIST_KEY = 'uam list';
    public const UAM_LIST_VALUES = 'uams_dhx2.so';

    private const NETATALK_SERVICE_NAME = 'netatalk';

    private Filesystem $filesystem;
    private Avahi $avahi;
    private Systemctl $systemctl;
    private DeviceLoggerInterface $logger;
    private FirewallService $firewallService;

    /** @var AfpShare[] */
    private array $afpShares;

    public function __construct(
        Filesystem $filesystem,
        Avahi $avahi,
        Systemctl $systemctl,
        DeviceLoggerInterface $logger,
        FirewallService $firewallService
    ) {
        $this->filesystem = $filesystem;
        $this->avahi = $avahi;
        $this->systemctl = $systemctl;
        $this->logger = $logger;
        $this->firewallService = $firewallService;

        $this->afpShares = [];

        $this->readConfig();
    }

    /**
     * Add a new share.
     */
    public function addShare(
        string $sharePath,
        string $shareName,
        bool $allowTimeMachine = true,
        string $allowedUsers = ''
    ): void {
        if (!$sharePath || !$shareName) {
            throw new Exception("Invalid share name");
        }
        $this->afpShares[$shareName] = new AfpShare(
            $sharePath,
            $shareName,
            'dbd',
            $allowTimeMachine,
            $allowedUsers == null ? '' : $allowedUsers
        );

        $this->sync();
    }

    /**
     * Remove a share.
     */
    public function removeShare(string $shareName): void
    {
        if (!$shareName) {
            throw new Exception("Invalid share name");
        }

        if (array_key_exists($shareName, $this->afpShares)) {
            unset($this->afpShares[$shareName]);

            $this->sync();
            return;
        }

        throw new Exception("Share not found");
    }

    /**
     * Change the allowed users list for a share.
     *
     * @param string $shareName The share name to update the list of users for
     * @param string $allowedUsers The list of users, space delimited
     */
    public function changeAllowedUsers(string $shareName, string $allowedUsers): void
    {
        if (!$shareName || !$allowedUsers) {
            throw new Exception("Invalid share or user list");
        }

        if (array_key_exists($shareName, $this->afpShares)) {
            $this->afpShares[$shareName]->setAllowedUsers($allowedUsers);
            $this->sync();
            return;
        }

        throw new Exception("Share not found");
    }

    /**
     * Get the list of shares.
     *
     * @return AfpShare[]
     */
    public function getShares(): array
    {
        $shareData = [];

        foreach ($this->afpShares as $afpShare) {
            $shareData[] = [
                'sharePath' => $afpShare->getSharePath(),
                'shareName' => $afpShare->getShareName(),
                'allowTimeMachine' => $afpShare->getAllowTimeMachine(),
                'allowedUsers' => $afpShare->getAllowedUsers(),
            ];
        }

        return $shareData;
    }

    /**
     * Get the path for a share.
     *
     * @param string $shareName
     * @return string
     */
    public function getSharePath(string $shareName): string
    {
        if (array_key_exists($shareName, $this->afpShares)) {
            return $this->afpShares[$shareName]->getSharePath();
        }

        return '';
    }

    /**
     * Synchronize configuration to disk and restart daemon.
     */
    public function sync(): void
    {
        $sections[] = "[Global]\nzeroconf = no\nlog level = debug\n"
            . self::UAM_LIST_KEY . " = " . self::UAM_LIST_VALUES;

        $shareNames = [];
        foreach ($this->afpShares as $afpShare) {
            $sections[] = $afpShare->outputString();
            $shareNames[] = $afpShare->getShareName();
        }

        $this->avahi->updateAvahiServicesForShares($shareNames, Avahi::AVAHI_SHARE_TYPE_AFP);
        $this->logger->debug('AFM0001 Writing afp.conf file');
        $this->filesystem->filePutContents(static::AFP_CONF_FILE, implode("\n\n", $sections));

        if (count($this->afpShares) === 0) {
            $this->firewallService->enableAfp(false);
            $this->logger->info('AFM0003 No AFP shares configured, stopping netatalk service');
            $this->systemctl->stop(self::NETATALK_SERVICE_NAME);
        } elseif (!$this->systemctl->isActive(self::NETATALK_SERVICE_NAME)) {
            $this->firewallService->enableAfp(true);
            $this->logger->info('AFM0002 AFP shares configured, starting netatalk service');
            $this->systemctl->start(self::NETATALK_SERVICE_NAME);
        } else {
            $this->firewallService->enableAfp(true);
            $this->systemctl->restart(self::NETATALK_SERVICE_NAME);
        }
    }

    private function readConfig(): void
    {
        if (!$this->filesystem->exists(static::AFP_CONF_FILE)) {
            $this->filesystem->touch(static::AFP_CONF_FILE);
        } else {
            $iniFileContents = $this->filesystem->parseIniFile(static::AFP_CONF_FILE, true, INI_SCANNER_TYPED);
            if ($iniFileContents) {
                foreach ($iniFileContents as $shareName => $shareConfiguration) {
                    if (strtoupper($shareName) === "GLOBAL") {
                        continue;
                    }
                    $this->afpShares[$shareName] = AfpShare::fromConfigSection($shareName, $shareConfiguration);
                }
            }
        }
    }
}
