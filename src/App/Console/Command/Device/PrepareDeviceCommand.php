<?php

namespace Datto\App\Console\Command\Device;

use Datto\Apache\ApacheService;
use Datto\Config\RlyConfig;
use Datto\Config\ServerNameConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Azure\InstanceMetadata;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\DirectToCloud\DirectToCloudService;
use Throwable;

/**
 * Unobfuscate source code.
 *
 * The name of the command is opaque on purpose
 * so as to hide its true nature.
 */
class PrepareDeviceCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEVICEWEB_HOST_TAG = 'devicewebhost';
    const RLY_TRACKER_HOST_TAG = 'rlytrackerhost';

    protected static $defaultName = 'device:prepare';

    /** @var string[] */
    const SOURCE_CODE_PATHS = [
        "/usr/lib/datto/device/src",
        "/usr/lib/datto/device/vendor/datto",
        "/datto/web"
    ];

    /** The spencercube preamble itself */
    const SPENCERCUBE_PREAMBLE = "<?php\neval(___(__FILE__));__halt_compiler();";

    /** length in bytes of spencercube preamble on disk */
    const SPENCERCUBE_PREAMBLE_LENGTH = 44;

    private Filesystem $filesystem;
    private DeviceConfig $deviceConfig;
    private InstanceMetadata $instanceMetadata;
    private ServerNameConfig $serverNameConfig;
    private ApacheService $apacheService;
    private DirectToCloudService $directToCloudService;
    private RlyConfig $rlyConfig;

    public function __construct(
        Filesystem $filesystem,
        DeviceConfig $deviceConfig,
        InstanceMetadata $instanceMetadata,
        ServerNameConfig $serverNameConfig,
        ApacheService $apacheService,
        DirectToCloudService $directToCloudService,
        RlyConfig $rlyConfig
    ) {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
        $this->instanceMetadata = $instanceMetadata;
        $this->serverNameConfig = $serverNameConfig;
        $this->apacheService = $apacheService;
        $this->directToCloudService = $directToCloudService;
        $this->rlyConfig = $rlyConfig;
    }

    public function isHidden(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();
        $this->setDescription("Pre-FPM preparation");
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->deviceConfig->isAzureDevice()) {
            if (!$this->instanceMetadata->isSupported()) {
                // Config says this is Azure device, but IMDS failed, so no IMDS dependant data is configured.
                $this->logger->error('PDC0003 Device is configured as Azure, but IMDS retrieval failed.');
                return 0;
            }

            $tags = $this->instanceMetadata->getTags();

            if (isset($tags[self::DEVICEWEB_HOST_TAG])) {
                $devicewebHost = $tags[self::DEVICEWEB_HOST_TAG];
                $this->setupCustomCountry($devicewebHost);
                $this->setDevDeploymentEnvironment();
                $this->setDevRlyTrackerHost($tags);
            }
            try {
                if ($this->hasDatacenterRegion()) {
                    $this->logger->debug('PDC0006 Datacenter is already configured, skipping configuration');
                    return 0;
                }
                // Extract datacenter region from metadata, add it to device config.
                $this->setDatacenterRegion($this->instanceMetadata->getLocation());
            } catch (Throwable $e) {
                $this->logger->error(
                    'PDC0004 Could not configure device datacenter location',
                    ['exception' => $e]
                );
            }
        }

        // short-circuit silently if not a cloud device.
        if (!$this->deviceConfig->isCloudDevice()) {
            return 0;
        }

        foreach (self::SOURCE_CODE_PATHS as $dir) {
            $this->unobfuscate($dir);
        }

        return 0;
    }

    /**
     * Recursive unobfuscation function. Basically does the opposite
     * of spencercube.
     *
     * @param string $directory
     */
    private function unobfuscate(string $directory)
    {
        $contents = $this->filesystem->glob("$directory/*");

        if (!is_array($contents)) {
            return;
        }

        foreach ($contents as $path) {
            if ($this->filesystem->isDir($path)) {
                $this->unobfuscate($path);
            } elseif (substr($path, -4) === '.php' && !$this->filesystem->isLink($path)) {
                $this->unobfuscateFile($path);
            }
        }
    }

    /**
     * Reverses spencercube on a file, writing it in-place if
     * the inflation succeeded and leaving it alone otherwise.
     *
     * @param string $file
     */
    private function unobfuscateFile($file)
    {
        $fileHandle = $this->filesystem->open($file, 'rb');
        $filesize = $this->filesystem->getSize($file);
        $matched = false;

        // we have to check whether the file is actually spencercubed by looking
        // for the preamble
        $check = @$this->filesystem->read($fileHandle, self::SPENCERCUBE_PREAMBLE_LENGTH);
        $isValidSpencercube = strcmp($check, self::SPENCERCUBE_PREAMBLE) === 0;

        if (!$isValidSpencercube) {
            $this->filesystem->close($fileHandle);
            return;
        }

        // read out the rest of the file and feed it to gzinflate
        $binary = @$this->filesystem->read($fileHandle, $filesize - self::SPENCERCUBE_PREAMBLE_LENGTH);

        $inflated = @gzinflate($binary);

        // if gzinflate errors out for whatever reason, it returns false.
        // we avoid replacing files that could not be ungzipped for
        // safety's sake.
        if ($inflated !== false) {
            $this->filesystem->filePutContents(
                $file,
                "<?php" . PHP_EOL . $inflated . PHP_EOL
            );
        }
        $this->filesystem->close($fileHandle);
    }

    /**
     * If possible, read a device-web devm hostname from Azure IMDS tags and set the country to it. This is used
     * to automatically pair a new device to a devm without having to use a custom image.
     */
    private function setupCustomCountry(string $devicewebHost): void
    {
        try {
            $country = 'AZUREDEVM';

            $this->logger->info('PDC0001 Found device-web host in Azure IMDS, setting custom country', [
                'country' => $country,
                'devicewebhost' => $devicewebHost
            ]);

            $this->serverNameConfig->setServers($country, [
                ServerNameConfig::DEVICE_DATTOBACKUP_COM => $devicewebHost
            ]);
            $this->serverNameConfig->setCountry($country);
        } catch (Throwable $e) {
            $this->logger->error('PDC0002 Could not setup custom country', ['exception' => $e]);
        }
    }

    /**
     * Sets the deployment environment for a dev device.
     */
    private function setDevDeploymentEnvironment(): void
    {
        $this->deviceConfig->set(DeviceConfig::KEY_DEPLOYMENT_ENVIRONMENT, DeviceConfig::DEV_DEPLOYMENT_ENVIRONMENT);
    }

    /**
     * Queries config as to whether datacenterRegion is set.
     *
     * @return bool true if datacenterRegion is configured, otherwise false.
     */
    private function hasDatacenterRegion()
    {
        return ($this->deviceConfig->hasDatacenterRegion());
    }

    /**
     * Sets the region (location) of the hosting datacenter for this device.
     *
     * @param string $regionName - datacenter region name to set.  Should not be empty.
     */
    private function setDatacenterRegion(string $regionName): void
    {
        $this->deviceConfig->setDatacenterRegion($regionName);
        $this->logger->info('PDC0005 Successfully set datacenter region', [
            'region' => $regionName
        ]);
    }

    private function setDevRlyTrackerHost(array $tags): void
    {
        if (isset($tags[self::RLY_TRACKER_HOST_TAG])) {
            $this->logger->info('PDC0004 Found rly tracker host in Azure IMDS, setting tracker host config', [
                self::RLY_TRACKER_HOST_TAG => $tags[self::RLY_TRACKER_HOST_TAG],
            ]);

            $trackerHosts = str_replace(',', ' ', $tags[self::RLY_TRACKER_HOST_TAG]);
            $this->rlyConfig->setTrackerHosts($trackerHosts);
        }
    }
}
