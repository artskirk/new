<?php

namespace Datto\Asset\Agent\Retention;

use Datto\Asset\Agent\AgentService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Deletes screenshot files that no longer have an associated backup
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ScreenshotRetentionService
{
    const SCREENSHOT_GLOB = '/datto/config/screenshots/%s.screenshot.*.*';
    const OUTDATED_PERIOD = '-1 month';

    /** @var AgentService */
    private $agentService;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param AgentService|null $agentService
     * @param Filesystem|null $filesystem
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(
        AgentService $agentService = null,
        Filesystem $filesystem = null,
        DateTimeService $dateTimeService = null
    ) {
        $this->agentService = $agentService ?: new AgentService();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
    }

    /**
     * Removes screenshots that don't have an accompanying snapshot.
     *
     * @param string $assetKey
     * @param DeviceLoggerInterface|null $logger
     */
    public function removeOutdatedScreenshots($assetKey, $logger = null): void
    {
        $points = $this->agentService->get($assetKey)->getLocal()->getRecoveryPoints()->getAll();
        $logger = $logger ?: LoggerFactory::getAssetLogger($assetKey);

        $points = array_keys($points);
        $globPattern = sprintf(static::SCREENSHOT_GLOB, $assetKey);
        $screenshotFiles = $this->filesystem->glob($globPattern);
        $outdatedTime = $this->dateTimeService->stringToTime(static::OUTDATED_PERIOD);

        $filesRemoved = 0;
        foreach ($screenshotFiles as $screenshotFile) {
            if (preg_match('/screenshot\.(\d+)\..*/', basename($screenshotFile), $screenshotPoint)) {
                $screenshotPoint = $screenshotPoint[1];
                $tooOld = $outdatedTime > $screenshotPoint;
                $snapExists = in_array($screenshotPoint, $points);
                if ($tooOld && !$snapExists) {
                    $this->filesystem->unlink($screenshotFile);
                    $filesRemoved++;
                }
            }
        }

        $logger->info('RET0500 Removed outdated screenshot files.', ['filesRemoved' => $filesRemoved]);
    }
}
