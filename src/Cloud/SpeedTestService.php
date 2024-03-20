<?php

namespace Datto\Cloud;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\LocalConfig;
use Datto\Config\ServerNameConfig;
use Datto\Configuration\RemoteSettings;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Service for running speed tests and uploading results to the webserver.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class SpeedTestService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SPEED_USERNAME = 'speedtest';
    const SPEED_PASSWORD = 'testspeed';
    const SPEED_FILE = '/tmp/speedFile';
    const SPEED_FILE_SIZE = 512 * 1024;
    const TXSPEED_CONFIG_KEY = 'txSpeed';

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var JsonRpcClient */
    private $client;

    /** @var LocalConfig */
    private $localConfig;

    /** @var RemoteSettings */
    private $remoteSettings;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        ServerNameConfig $serverNameConfig,
        ProcessFactory $processFactory,
        JsonRpcClient $client,
        LocalConfig $localConfig,
        RemoteSettings $remoteSettings,
        Filesystem $filesystem
    ) {
        $this->serverNameConfig = $serverNameConfig;
        $this->processFactory = $processFactory;
        $this->client = $client;
        $this->localConfig = $localConfig;
        $this->remoteSettings = $remoteSettings;
        $this->filesystem = $filesystem;
    }

    /**
     * Create a 512kb speedtest file. Returns true if the file exists after the
     * function runs
     *
     * @return bool
     */
    protected function createSpeedFile()
    {
        if (!$this->filesystem->exists(static::SPEED_FILE)) {
            $fp = $this->filesystem->open(static::SPEED_FILE, 'w');
            $this->filesystem->truncate($fp, static::SPEED_FILE_SIZE);
            $this->filesystem->close($fp);
        }

        return $this->filesystem->exists(static::SPEED_FILE);
    }

    /**
     * Runs a speed test and uploads the result to the webserver.
     */
    public function runSpeedTest(int $defaultSpeedInKBps = null)
    {
        try {
            $speed = $this->calculateMaximumUploadSpeed();
        } catch (Throwable $e) {
            $this->logger->error('SPD0000 Speedtest failed', ['exception' => $e->getMessage()]);

            if (!is_null($defaultSpeedInKBps)) {
                $speed = $defaultSpeedInKBps;
            } else {
                throw $e;
            }
        }
        $this->updateSpeed($speed);
    }

    /**
     * Gets the speed of the connection between the device and Datto's webserver.
     *
     * @return float|int
     */
    public function calculateMaximumUploadSpeed()
    {
        if (!$this->createSpeedFile()) {
            throw new Exception('Failed to create speedFile');
        }

        // Set up the speedtest process
        // 2>&1 ncftpput -u %s -p %s -t 10 -r 10 speedtest.dattobackup.com "/" "%s"
        $process = $this->processFactory->get([
                'ncftpput',
                '-u',
                static::SPEED_USERNAME,
                '-p',
                static::SPEED_PASSWORD,
                '-t',
                10,
                '-r',
                10,
                $this->serverNameConfig->getServer(ServerNameConfig::SPEEDTEST_DATTOBACKUP_COM),
                '/',
                static::SPEED_FILE
            ]);
        $this->logger->info('SPD0001 Running command for speed test', ['commandLine' => $process->getCommandLine()]);
        $process->mustRun();

        // Parse process output
        // Error output from process looks like (\r delimited):
        //   speedFile:                                             32.00/512.00 kB  328.95 MB/s\r
        //   ...
        //   speedFile:                                             512.00 kB        4.54 MB/s
        $output = $process->getErrorOutput();
        $outputLines = explode("\r", $output);
        $tokens = preg_split('/\s+/', $outputLines[count($outputLines) - 1]);
        if (count($tokens) < 5) {
            throw new RuntimeException('Unexpected output from ncftpput: ' . $output);
        }
        $speed = (double)$tokens[3];
        $unit = $tokens[4];

        // Normalize to KB/s
        if ($unit === 'MB/s') {
            $speed *= 1024;
        }

        $this->logger->info('SPD0002 Speedtest ran successfully', ['speed' => $speed]);

        $this->filesystem->unlink(static::SPEED_FILE);

        return $speed;
    }

    private function updateSpeed(float $speedInKBps)
    {
        try {
            $this->logger->info('SPD0003 Updating speed locally and on the webserver', ['speedInKBps' => $speedInKBps]);
            $this->localConfig->set(static::TXSPEED_CONFIG_KEY, $speedInKBps);
            $this->remoteSettings->setOffsiteSyncSpeed($speedInKBps);
        } catch (Throwable $e) {
            $this->logger->error('SPD0004 Updating speed failed', ['exception' => $e->getMessage()]);
            throw $e;
        }
    }
}
