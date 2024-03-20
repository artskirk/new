<?php

namespace Datto\License;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Common\Resource\CurlRequest;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;

/**
 * Class LicenseService provides licensing determination and reporting functionality for the device.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class LicenseService
{
    const SECRET_KEY_KEY = 'secretKey';
    const SERVER_KEY = 'DEVICE_DATTOBACKUP_COM';

    const LOCKFILE = '/tmp/datto-licAudit.lock';
    const KEY_SCRIPT_PATH = '/datto/scripts/secretKey.sh';
    const LICENSING_ENDPOINT = 'licAudit.php';

    const DESKTOP_LICENSE = 'desktop';
    const SERVER_LICENSE = 'server';

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var AgentService */
    private $agentService;

    /** @var CurlRequest */
    private $curlRequest;

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var Filesystem */
    private $fileSystem;

    /** @var Lock */
    private $lock;

    public function __construct(
        DeviceConfig $deviceConfig,
        AgentService $agentService,
        CurlRequest $curlRequest,
        ServerNameConfig $serverNameConfig,
        Filesystem $fileSystem,
        LockFactory $lockFactory
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->agentService = $agentService;
        $this->curlRequest = $curlRequest;
        $this->serverNameConfig = $serverNameConfig;
        $this->fileSystem = $fileSystem;
        $this->lock = $lockFactory->create(self::LOCKFILE);
    }

    /**
     * Audit the device for licensing and report agent counts back to the DLAMP server.
     *
     * @param bool $noSleep whether or not to sleep for a random period of time between 1 and 150 seconds prior to auditing.
     */
    public function auditLicensesAndReport($noSleep = false)
    {
        if ($this->lock->isLocked()) {
            throw new \Exception("Found lock file, exiting");
        }

        $lockFile = $this->lock->path();
        $this->fileSystem->filePutContents($lockFile, strval(posix_getpid()), LOCK_EX);
        $this->lock->exclusive(false);

        if (!$noSleep) {
            sleep(rand(1, 150));
        }

        $secretKey = $this->deviceConfig->get(self::SECRET_KEY_KEY);
        $licenseCounts = $this->getLicenseCounts();

        $url = 'https://' . $this->serverNameConfig->getServer(self::SERVER_KEY) . '/' . self::LICENSING_ENDPOINT . '?secretKey='
            . $secretKey .'&sDesktop=' . $licenseCounts[self::DESKTOP_LICENSE] . '&sServer=' . $licenseCounts[self::SERVER_LICENSE];

        $this->curlRequest->init($url);
        $this->curlRequest->execute();
        $this->curlRequest->close();

        $this->lock->unlock();
        $this->fileSystem->unlink($lockFile);
    }

    /**
     * Get the license counts for the device.
     *
     * @return array with keys LicenseService::DESKTOP_LICENSE and LicenseService::SERVER_LICENSE containing the counts
     * for each as their respective values.
     */
    public function getLicenseCounts()
    {
        $desktopCount = 0;
        $serverCount = 0;
        foreach ($this->agentService->getAll() as $agent) {
            if ($agent instanceof WindowsAgent) {
                $desktopCount++;
            } else {
                $serverCount++;
            }
        }
        return array(
            self::DESKTOP_LICENSE => $desktopCount,
            self::SERVER_LICENSE => $serverCount
        );
    }
}
