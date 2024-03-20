<?php

namespace Datto\Upgrade;

use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\ServerNameConfig;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Utility\Screen;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class UpgradeService
 * Handles upgrading both the device and the upgrade utility based on the selected channel.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class UpgradeService
{
    const GET_UPGRADECTL_URL = 'v1/device/upgrade/release/getLatestByName';
    const UPGRADECTL_BIN = '/usr/local/bin/upgradectl';
    const DEVICE_DATTOBACKUP_COM = 'DEVICE_DATTOBACKUP_COM';
    const UPGRADECTL_SCREEN_HASH = 'upgradectl';
    const UPGRADECTL_ARG_UPGRADE_LATEST_NOW = 'device:upgradeLatestNow';
    const UPGRADE_FAILURE_FLAG = '/host/log/faillog';
    const UPGRADE_FAIL_TIME_WINDOW = 86400; // one day in seconds
    const MAX_QUERY_RETRIES = 3;
    const SLEEP_SECONDS = 5;

    /*
     * This file is created by upgradectl.
     */
    const UPGRADE_STATUS_FILE_PATH = '/dev/shm/upgrade.status';
    const STATUS_UPGRADE_AVAILABLE = 'UPGRADE_AVAILABLE';
    const STATUS_UPGRADE_RUNNING = 'UPGRADE_RUNNING';

    /** @var string[]|null */
    private $releaseInfo = null;

    /** @var Screen|null */
    private $screen = null;

    /** @var ChannelService */
    private $channelService;

    private ProcessFactory $processFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var JsonRpcClient */
    private $client;

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $time;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        ChannelService $channelService = null,
        ProcessFactory $processFactory = null,
        DeviceLoggerInterface $logger = null,
        JsonRpcClient $client = null,
        ServerNameConfig $serverNameConfig = null,
        Filesystem $filesystem = null,
        Screen $screen = null,
        DateTimeService $time = null,
        Sleep $sleep = null
    ) {
        $this->channelService = $channelService ?: new ChannelService();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->client = $client ?: new JsonRpcClient();
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->screen = $screen ?: new Screen($this->logger);
        $this->time = $time ?: new DateTimeService();
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Compares the available and current upgradectl hash. If they're different, download the upgradectl.
     * Otherwise do nothing.
     */
    public function upgradeToLatestUpgradectl()
    {
        $currentVersion = $this->getCurrentUpgradectlVersion();
        $currentHash = $this->getCurrentUpgradectlHash();
        $availableHash = $this->getAvailableUpgradectlHash();
        $availableVersion = $this->getAvailableUpgradectlVersion();

        if ($availableHash !== $currentHash) {
            $this->logger->info('UPG0001 Upgrading upgradectl', ['fromVersion' => $currentVersion, 'toVersion' => $availableVersion]);
            $this->installLatestUpgradectl();
            $this->logger->info('UPG0002 Successfully upgraded upgradectl', ['version' => $availableVersion]);
        } else {
            $this->logger->info('UPG0003 Upgradectl already at latest version', ['currentVersion' => $currentVersion]);
        }
    }

    /**
     * Upgrades device to the latest available image.
     */
    public function upgradeToLatestImage()
    {
        try {
            $process = $this->processFactory->get(['upgradectl', self::UPGRADECTL_ARG_UPGRADE_LATEST_NOW, '--background']);
            $process->mustRun();

            $this->logger->info('UPG0011 Started upgrade process to latest image. See /host/log/upgradectl.log for more information.');
        } catch (\Exception $e) {
            $this->logger->info('UPG0012 Failed to upgrade to latest image');
            throw new \Exception('Failed to upgrade image', $e->getCode(), $e);
        }
    }

    /**
     * Gets the current image.
     *
     * @return string
     */
    public function getCurrentImage() : string
    {
        // TODO: use /datto/config/imageVer when possible (awaiting release of CP-12133)

        $process = $this->processFactory->get(['upgradectl', 'status', '--release']);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    /**
     * Checks if the upgrade is in progress
     *
     * @return bool
     */
    public function isUpgradeRunning() : bool
    {
        return $this->screen->isScreenRunning(static::UPGRADECTL_SCREEN_HASH);
    }

    /**
     * Checks if an upgrade was successful
     *
     * @return bool
     */
    public function upgradeSuccessful() : bool
    {
        if ($this->filesystem->exists(static::UPGRADE_FAILURE_FLAG)) {
            $now = $this->time->getTime();
            $lastModified = $this->filesystem->fileMTime(static::UPGRADE_FAILURE_FLAG);
            // older than the interval means it's not related
            return $lastModified < ($now - static::UPGRADE_FAIL_TIME_WINDOW);
        }

        return true;
    }

    /**
     * Set channel to the default channel.
     */
    public function setChannelToDefaultIfNotSet()
    {
        $channel = $this->getChannel();

        if ($channel === ChannelService::NO_CHANNEL_SELECTED) {
            $default = $this->channelService->getDefault();
            $this->channelService->setChannel($default);
        }
    }

    /**
     * Get current upgradectl hash.
     *
     * @return string
     */
    private function getCurrentUpgradectlHash() : string
    {
        $upgradectl = $this->filesystem->fileGetContents(static::UPGRADECTL_BIN);
        return md5($upgradectl);
    }

    /**
     * Get current upgradectl version.
     *
     * @return string
     */
    private function getCurrentUpgradectlVersion() : string
    {
        $process = $this->processFactory->get(['upgradectl', '--version']);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    /**
     * @return string
     */
    private function getAvailableUpgradectlVersion() : string
    {
        return $this->getReleaseInfo()['release']['upgraderVersion']['version'];
    }

    /**
     * @return string
     */
    private function getAvailableUpgradectlHash() : string
    {
        return $this->getReleaseInfo()['release']['upgraderVersion']['hash'];
    }

    /**
     * Install the latest version of upgradectl
     */
    private function installLatestUpgradectl()
    {
        $relativeUrl = $this->getReleaseInfo()['release']['upgraderVersion']['relativeUrl'];
        $latestUpgradectlUrl = 'https://' . $this->serverNameConfig->getServer(static::DEVICE_DATTOBACKUP_COM) . $relativeUrl;

        $retries = 0;
        $maxRetries = self::MAX_QUERY_RETRIES;
        $retrySeconds = self::SLEEP_SECONDS;
        while (true) {
            try {
                $this->logger->info('UPG0006 Downloading upgradectl', ['latestUpgradeUrl' => $latestUpgradectlUrl]);
                $this->wgetUpgradectl($latestUpgradectlUrl);
                $this->makeUpgradectlExecutable();
                break;
            } catch (\Exception $exception) {
                if ($retries < $maxRetries) {
                    // Wait and try again
                    $this->logger->warning('UPG0016 Error upgrading upgradectl, retrying...', ['secondsBetweenRetries' => $retrySeconds]);
                    $this->sleep->sleep($retrySeconds);
                    $retries++;
                } else {
                    $this->logger->error('UPG0007 Failed to download upgradectl', ['exception' => $exception]);
                    throw new \Exception('Failed to download upgradectl', $exception->getCode(), $exception);
                }
            }
        }
    }

    /**
     * @param bool $forceGetLatest
     * @return array|null|string
     */
    private function getReleaseInfo($forceGetLatest = false)
    {
        if ($this->releaseInfo === null || $forceGetLatest) {
            $retries = 0;
            $maxRetries = self::MAX_QUERY_RETRIES;
            $retrySeconds = self::SLEEP_SECONDS;
            while (true) {
                try {
                    $params = ['channelName' => $this->getChannel()];
                    $this->logger->info('UPG0008 Downloading release information', ['upgradectlUrl' => static::GET_UPGRADECTL_URL]);
                    $this->releaseInfo = $this->client->queryWithId(static::GET_UPGRADECTL_URL, $params);
                    break;
                } catch (\Exception $exception) {
                    if ($retries < $maxRetries) {
                        // Wait and try again
                        $this->logger->warning(
                            'UPG0009 Error downloading release information, retrying...',
                            ['upgradectlUrl' => static::GET_UPGRADECTL_URL, 'secondsBetweenRetries' => $retrySeconds]
                        );
                        $this->sleep->sleep($retrySeconds);
                        $retries++;
                    } else {
                        $this->logger->error(
                            'UPG0010 Failed to download release information',
                            ['upgradectlUrl' => static::GET_UPGRADECTL_URL, 'exception' => $exception]
                        );
                        throw $exception;
                    }
                }
            }
        }

        return $this->releaseInfo;
    }

    /**
     * @param string $url
     */
    private function wgetUpgradectl($url)
    {
        $process = $this->processFactory->get(['wget', $url, '-O', static::UPGRADECTL_BIN]);
        $process->mustRun();
    }

    /**
     * Update upgradectl to be executable
     */
    private function makeUpgradectlExecutable()
    {
        $process = $this->processFactory->get(['chmod', '+x', static::UPGRADECTL_BIN]);
        $process->mustRun();
    }

    /**
     * Gets selected channel for the device
     *
     * @return string
     */
    private function getChannel() : string
    {
        $this->channelService->updateCache(); // Refresh cache
        return $this->channelService->getChannels()->getSelected();
    }

    /**
     * @return string|null
     */
    public function getStatus()
    {
        if (!$this->filesystem->exists(self::UPGRADE_STATUS_FILE_PATH)) {
            return null;
        }
        $json = $this->filesystem->fileGetContents(self::UPGRADE_STATUS_FILE_PATH);
        $data = json_decode($json, true);

        return $data['status'] ?? null;
    }
}
