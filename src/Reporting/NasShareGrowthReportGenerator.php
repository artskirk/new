<?php

namespace Datto\Reporting;

use Datto\Afp\AfpVolumeManager;
use Datto\App\Twig\FormatExtension;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\FileConfig;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Curl\CurlHelper;
use Datto\Device\Serial;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Samba\SambaManager;
use Datto\Nfs\NfsExportManager;
use Datto\Samba\SambaShare;
use Datto\Utility\ByteUnit;
use Datto\Utility\Network\Hostname;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Generator for NAS Share growth reports
 */
class NasShareGrowthReportGenerator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array Stored agent information */
    private array $agentInfo = [];

    /** @var SambaShare The SambaShare object containing the share information */
    private SambaShare $sambaShare;

    /** @var string Share name */
    private string $keyName;

    /** @var string The stored frequency of the growth report. */
    public string $growthReportFrequency = '';

    /** @var ?string The stored timestamp of the last sent report */
    public ?string $growthReportLastSent  = null;

    /** @var array The stored list of emails to send the report to */
    private array $growthReportEmailList = [];

    /** @var string The ID of the dataset for the currently loaded share */
    private string $datasetId;

    private CurlHelper $curlHelper;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;
    private FormatExtension $formatExtension;
    private AgentConfigFactory $agentConfigFactory;
    private AfpVolumeManager $afpVolumeManager;
    private NfsExportManager $nfsExportManager;
    private SambaManager $sambaManager;
    private DeviceConfig $deviceConfig;
    private AssetService $assetService;
    private ShareService $shareService;
    private Serial $serial;
    private DateTimeService $dateTime;
    private Hostname $hostname;

    public function __construct(
        CurlHelper $curlHelper,
        StorageInterface $storage,
        SirisStorage $sirisStorage,
        FormatExtension $formatExtension,
        AgentConfigFactory $agentConfigFactory,
        AfpVolumeManager $afpVolumeManager,
        NfsExportManager $nfsExportManager,
        SambaManager $sambaManager,
        DeviceConfig $deviceConfig,
        AssetService $assetService,
        ShareService $shareService,
        Serial $serial,
        DateTimeService $dateTime,
        Hostname $hostname
    ) {
        $this->curlHelper = $curlHelper;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
        $this->formatExtension = $formatExtension;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->afpVolumeManager = $afpVolumeManager;
        $this->nfsExportManager = $nfsExportManager;
        $this->sambaManager = $sambaManager;
        $this->deviceConfig = $deviceConfig;
        $this->assetService = $assetService;
        $this->shareService = $shareService;
        $this->serial = $serial;
        $this->dateTime = $dateTime;
        $this->hostname = $hostname;
    }

    public function sendAllGrowthReports()
    {
        $nasAgents = $this->shareService->getAll(AssetType::NAS_SHARE);

        foreach ($nasAgents as $share) {
            $nasAgent = $share->getKeyName();

            // Loop through the agents
            try {
                // Attempt to instantiate a GrowthReportGenerator object
                $this->load($nasAgent);
            } catch (Exception $e) {
                // There was a problem, log it and continue
                $this->logger->warning(
                    'SNS0062 Growth could not be logged for share',
                    ['share' => $nasAgent, 'exception' => $e]
                );
                continue;
            }

            if ($this->dateTime->getDate('G') === '0') {
                // It's Midnight!

                // We send reports based on stored frequency
                $frequency = $this->growthReportFrequency;
                $sendReport = $frequency === 'daily' ||
                    ($frequency === 'weekly' && $this->dateTime->getDayOfWeek($this->dateTime->getTime()) === 0) || // on sunday
                    ($frequency === 'monthly' && $this->dateTime->getDate('j') === '1'); // on the 1st of the month

                if ($sendReport) {
                    // Let's CURL it!
                    $response = $this->sendGrowthReport();
                    if ($response) {
                        // Aww yeah!
                        $this->logger->info(
                            'SNS1200 Sent growth report for share',
                            [
                                'share' => $nasAgent,
                                'growthReportFrequency' => $this->growthReportFrequency
                            ]
                        );
                    } else {
                        // Oh snap!
                        $this->logger->error(
                            'SNS1201 Could not send growth report for share',
                            [
                                'share' => $nasAgent,
                                'growthReportFrequency' => $this->growthReportFrequency
                            ]
                        );
                    }
                }
            }

            // SnapNAS Agent -- Take Usage Readings HOURLY (regardless of frequency)
            $this->updateUsageLogs();
            $this->logger->info('SNS0060 Growth logged for share', ['share' => $nasAgent]);
        }
    }

    /**
     * Loads the share growth for a share with the provided key name
     *
     * @param string $keyName  The keyName of the agent
     * @return bool Whether the agent was properly loaded
     * @throws Exception
     */
    public function load(string $keyName): bool
    {
        $this->setName($keyName);

        $this->logger->info('SNS0002 Loading NAS Share', ['keyName' => $keyName]);

        $agentConfig = $this->agentConfigFactory->create($keyName);
        $info = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);

        if ($info === false) {
            $this->logger->error('SNS0003 Share does not exist', ['keyName' => $keyName]);
            return false;
        }

        $this->agentInfo = $info;
        $this->datasetId = Share::BASE_ZFS_PATH . '/' . $keyName;

        if (!$this->verifySambaShare()) {
            throw new Exception("The dataset is properly loaded, however, the samba share $keyName does not exist.");
        }

        $name = $info['name']; // we must use name, not keyName since the share is mounted at /datto/mounts/<name>
        if ($this->afpVolumeManager->getSharePath($name)) {
            $afpEnabled = true;
        } else {
            $afpEnabled = false;
        }

        $nfsEnabled = $this->nfsExportManager->isEnabled($this->getSambaShare()->getPath());

        $this->updateAgentInfo($afpEnabled, $nfsEnabled);

        // Load growth report parameters
        $this->loadGrowthReportSettings();

        $this->logger->info('SNS0004 NAS Share loaded.', ['keyName' => $keyName]);

        return true;
    }

    /**
     * Gets the current used size of the loaded share (currently shared files or dataset including snapshots/base)
     *
     * @param string $source  (file|dataset)
     * @return int
     */
    private function getUsedSize(string $source): int
    {
        $usedSize = -1;
        try {
            $storageInfo = $this->storage->getStorageInfo($this->datasetId);

            switch ($source) {
                case 'file':
                    // Files
                    $usedSize = $storageInfo->getAllocatedSizeByStorageInBytes();
                    break;
                case 'dataset':
                    // Files + snapshots
                    $usedSize = $storageInfo->getAllocatedSizeInBytes();
                    break;
            }
        } catch (Exception $e) {
            $this->logger->warning(
                "SNS0005 Data-set allocated info could not be retrieved",
                ["keyName" => $this->keyName, "datasetId" => $this->datasetId]
            );
            $usedSize = 0;
        }

        return $usedSize;
    }

    // Class-specific functions
    /**
     * Returns the stored SambaShare object
     *
     * @return SambaShare
     */
    private function getSambaShare(): SambaShare
    {
        return $this->sambaShare;
    }

    /**
     * Usage info will be stored in a key and updated / purged every 31 days
     */
    public function updateUsageLogs(): void
    {
        $config = $this->agentConfigFactory->create($this->keyName);

        $usedByFiles = $this->getUsedSize('file');
        $usedByZFS = $this->getUsedSize('dataset');
        $timestamp = $this->dateTime->getTime();

        /**
         * Formatted as:
         *     {epoch time}:{file size/B}:{dataset size/B}
         */

        $hourlyUsage = $config->getRaw('usage.hourly', "");
        $hourlyUsage .= $timestamp . ':' . $usedByFiles . ':' . $usedByZFS . "\n";
        $config->setRaw('usage.hourly', $hourlyUsage);

        $this->trimUsageLogs('hourly');

        if (intval($this->dateTime->getDate('G')) === 23) {
            // It's the last log of the day, add it to 'daily'
            $dailyUsage = $config->getRaw('usage.daily', "");
            $dailyUsage .= $timestamp . ':' . $usedByFiles . ':' . $usedByZFS . "\n";
            $config->setRaw('usage.daily', $dailyUsage);

            $this->trimUsageLogs('daily');
        }
    }

    /**
     * Loads the growth report settings to object parameters.
     */
    private function loadGrowthReportSettings(): void
    {
        $config = $this->agentConfigFactory->create($this->keyName);

        if ($config->has('growthReport')) {
            $growthString = $config->getRaw('growthReport');
            list($frequency, $lastSent, $emailList) = explode(':', $growthString);
            $this->growthReportFrequency = $frequency;
            $this->growthReportLastSent = $lastSent;
            $this->growthReportEmailList = explode(',', $emailList);
        }
    }

    /**
     * Trims the usage logs appropriatly
     */
    private function trimUsageLogs(string $logType): void
    {
        $config = $this->agentConfigFactory->create($this->keyName);

        // Switch based on log we want to trim
        switch ($logType) {
            case 'hourly':
                $maxInterval = '-48 hours'; // keep hourly data for 48 hours
                $keyToTrim = 'usage.hourly';
                break;

            case 'display':
                $maxInterval = '-60 days'; // keep daily data for 60 days
                $keyToTrim = 'usage.daily';
                break;

            default:
                // Yeah, you didn't specify
                return;
        }

        if ($config->has($keyToTrim)) {
            $fileLines = explode("\n", $config->getRaw($keyToTrim));
            $fileLineSplit = -1;
            // Not using krsort here as we need to put it back in order, decrementing lines
            for ($i = (count($fileLines) - 1); $i >= 0; $i--) {
                // Each line has {timestamp}:{usedByFileSize}:{usedByZFSSize}
                $timestamp = explode(':', $fileLines[$i])[0];
                if ($timestamp < $this->dateTime->stringToTime($maxInterval)) {
                    $fileLineSplit = $i;
                    break;
                }
            }

            if ($fileLineSplit >= 0) {
                $config->setRaw(
                    $keyToTrim,
                    implode("\n", array_slice($fileLines, $fileLineSplit + 1))
                );
            }
        }
    }

    /**
     * Generates a growth report array (to be sent via cron/curl) based on loaded parameters
     *
     * @param bool $isTest  Whether test data should be loaded
     * @return array  The array of growth report data for this share
     */
    public function generateGrowthReport(bool $isTest): array
    {
        // Initialize values
        $focusedUsage = [];
        $lastTimestamp = $this->dateTime->getTime();

        switch ($this->growthReportFrequency) {
            case 'daily':
                // Settings for DAILY reports (24 hours)
                $propertyName = 'usage.hourly';
                $maxDatapoints = 24;
                $dateLimit     = '-24 hours';
                $dateCompare   = 'YmdH';
                break;

            case 'weekly':
                // Settings for WEEKLY reports (7 Days)
                $propertyName = 'usage.daily';
                $maxDatapoints = 7;
                $dateLimit     = '-7 days';
                $dateCompare   = 'Ymd';
                break;

            default:
            case 'monthly':
                // Settings for monthly reports (31 Days)
                $propertyName = 'usage.daily';
                $maxDatapoints = 31;
                $dateLimit     = '-31 days';
                $dateCompare   = 'Ymd';
                break;
        }

        // To test or not to test...
        if (!$isTest) {
            $rawUsage = $this->getGrowthFromFile($propertyName);
        } else {
            $rawUsage = $this->generateTestGrowth($this->growthReportFrequency);
        }

        foreach ($rawUsage as $usageStat) {
            // Each line has {timestamp}:{usedByFileSize}:{usedByZFSSize}
            list($timestamp, $usedByFiles, $usedByZFS) = explode(':', $usageStat);

            $usedByFiles = intval($usedByFiles);
            $usedByZFS = intval($usedByZFS);

            if ($timestamp > $this->dateTime->stringToTime($dateLimit)) {
                // The timestamp is within our limit
                if (intval($this->dateTime->getDate($dateCompare, $timestamp)) <= intval($this->dateTime->getDate($dateCompare, $lastTimestamp))
                           && $maxDatapoints != 0
                ) {
                    // Record the datapoint
                    $focusedUsage[$timestamp] = [
                        'totalFileSize' => intval(round($usedByFiles / 1024)),
                        'totalSize' => intval(round($usedByZFS / 1024))
                    ];

                    // Decrement the max
                    $maxDatapoints--;

                    // Last timestamp update
                    $lastTimestamp = $timestamp;
                }
            } else {
                // Bust out of the loop, any older data won't be used
                break;
            }
        }

        // BLANK DATAPOINTS (this share may not have existed yet, MARK IT ZERO!)
        for ($i = $maxDatapoints; $i > 0; $i--) {
            if ($this->growthReportFrequency === 'daily') {
                $lastTimestamp = $this->dateTime->stringToTime('-1 hour', $lastTimestamp);
            } else {
                $lastTimestamp = $this->dateTime->stringToTime('-1 day', $lastTimestamp);
            }
            $focusedUsage[$lastTimestamp] = [
                'totalFileSize' => 0,
                'totalSize' => 0
            ];
        }

        ksort($focusedUsage);

        // Return the array (empty or otherwise)
        return $focusedUsage;
    }

    /**
     * Generates true growth stored in key
     *
     * @param string $propertyName The name of the key file to be extracted
     * @return array  The growth data
     */
    private function getGrowthFromFile(string $propertyName): array
    {
        $config = $this->agentConfigFactory->create($this->keyName);

        $rawUsage = [];
        if ($config->has($propertyName)) {
            // Grab the file as an array
            $rawUsage = explode("\n", $config->getRaw($propertyName));
            // Reverse sort by key (numeric)
            krsort($rawUsage);
        }

        return $rawUsage;
    }

    /**
     * Generates faux growth data based on frequency
     *
     * @param string $frequency  The frequency the data should encompass
     * @return array  The faux growth data
     */
    private function generateTestGrowth(string $frequency): array
    {
        $rawUsage = [];

        $interval = ($frequency === 'daily') ? '-1 hour' : '-1 day';

        $fauxFiles = ByteUnit::GIB()->toByte(rand(1, 4000));
        $fauxZFS = $fauxFiles + ByteUnit::GIB()->toByte(rand(1, 100));

        $lastTimestamp = $this->dateTime->getTime();
        for ($i = 0; $i < 60; $i++) {
            $rawUsage[] = $lastTimestamp . ':' . $fauxFiles . ':' . $fauxZFS;
            $fauxFiles += ByteUnit::GIB()->toByte(rand(-500, 500));
            if ($fauxFiles < ByteUnit::GIB()->toByte(500)) {
                $fauxFiles = ByteUnit::GIB()->toByte(500);
            }
            $fauxZFS = $fauxFiles + ByteUnit::GIB()->toByte(rand(1, 100));
            $lastTimestamp = $this->dateTime->stringToTime($interval, $lastTimestamp);
        }

        return $rawUsage;
    }

    /**
     * Generates and emails a growth report for THIS agent.
     *
     * @param bool $isTest  Whether or not to send a test email (with faux data)
     * @return bool  Success or Failure
     */
    public function sendGrowthReport(bool $isTest = false): bool
    {
        $agentReport = $this->generateGrowthReport($isTest);

        if (!empty($agentReport)) {
            return $this->emailGrowthReport($agentReport, $isTest);
        }

        return false;
    }

    /**
     * Send a growth report email with the provided report
     *
     * @param array $agentReport
     * @param bool $isTest
     * @return bool
     */
    public function emailGrowthReport(array $agentReport, bool $isTest): bool
    {
        // Report data exists, let's send it!
        $emailSubjects = $this->getSubject();
        $emailSubject = ucfirst($this->growthReportFrequency) . ' ' . $emailSubjects['growth'];

        $reports = [
            'frequency'    => $this->growthReportFrequency,
            'emailTo'      => trim(implode(',', $this->growthReportEmailList)),
            'emailSubject' => ($isTest ? 'Test Email - ' : '') . $this->parseSpecial($this->keyName, $emailSubject),
            'sizeIn'       => 'kB',
            'shares'       => [$this->getDisplayName() => $agentReport]
        ];

        // Let's CURL it!
        $response = $this->curlHelper->email('growthReport', $reports);
        if ($response === '1') {
            return true;
        }
        return false;
    }

    /**
     * Updates agent info (currently with size information)
     */
    private function updateAgentInfo(bool $afpEnabled, bool $nfsEnabled): void
    {
        $agentConfig = $this->agentConfigFactory->create($this->keyName);
        // Reload agent info as it could be altered by another process
        $rawAgentInfo = $agentConfig->get("agentInfo");

        if ($rawAgentInfo === false || $rawAgentInfo === FileConfig::ERROR_RESULT) {
            $this->logger->error('SNS9002 AgentInfo key file does not exist!', ['keyName' => $this->keyName]);
            return;
        }

        $this->agentInfo = unserialize($rawAgentInfo, ['allowed_classes' => false]);

        $snapshots = $this->storage->listSnapshotIds($this->datasetId, false);
        $usedBySnapshots = 0;
        foreach ($snapshots as $snapshot) {
            $usedBySnapshots += $this->storage->getSnapshotInfo($snapshot)->getUsedSizeInBytes();
        }

        $updateInfo = [
            'afpEnabled' => $afpEnabled,
            'nfsEnabled' => $nfsEnabled,
            'localUsed' => ByteUnit::BYTE()->toGiB($this->getUsedSize('dataset')),
            'usedBySnaps' => ByteUnit::BYTE()->toGiB($usedBySnapshots),
        ];

        $this->agentInfo = array_merge($this->agentInfo, $updateInfo);

        $agentConfig->set("agentInfo", serialize($this->agentInfo));
    }

    /**
     * Verifies the existence of a samba share associated with the loaded share
     *
     * @return bool  Whether the samba share exists
     */
    private function verifySambaShare(): bool
    {
        $share = $this->sambaManager->getShareByName($this->getDisplayName());
        if ($share != null) {
            $this->sambaShare = $share;
            return true;
        }

        return false;
    }

    private function getDisplayName(): string
    {
        $agentConfig = $this->agentConfigFactory->create($this->keyName);
        return $agentConfig->getName();
    }

    /**
     * Sets the name of the share
     *
     * @param string $keyName
     */
    private function setName(string $keyName): void
    {
        $this->keyName = $keyName;
        $this->logger->setAssetContext($this->keyName);
    }

    private function getSubject(): array
    {
        if ($this->deviceConfig->has('subject.emails')) {
            return unserialize($this->deviceConfig->get('subject.emails'), ['allowed_classes' => false]);
        } else {
            return [
                'screenshots' => 'Bootable Screenshot for -agenthostname on -devicehostname (-sn) -shot',
                'weeklys' => 'Weekly Backup Report for -devicehostname',
                'critical' => 'CRITICAL ERROR for -agenthostname on -devicehostname (-sn)',
                'missed' => 'Warning for -agenthostname on -devicehostname (-sn)',
                'notice' => '',
                'logs' => 'Logs for -agenthostname on -devicehostname (-sn)',
                'growth' => 'Growth Report for -agenthostname on -devicehostname (-sn)'
            ];
        }
    }

    /**
     * Replace placeholders in $string with 'log severity special' data
     *
     * @param string $hostname the name of the agent
     * @param string $string the string to replace
     * @return string $string with values replaced
     */
    private function parseSpecial(string $hostname, string $string): string
    {
        $poolInfo = $this->storage->getPoolInfo(SirisStorage::PRIMARY_POOL_NAME);
        $storageInfo = $this->storage->getStorageInfo($this->sirisStorage->getHomeStorageId());

        $freeSpace = $this->formatExtension->formatBytes($storageInfo->getAllocatedSizeInBytes(), 1);
        $usedspace  = $this->formatExtension->formatBytes($storageInfo->getAllocatedSizeInBytes(), 1);
        $percentused  = $poolInfo->getAllocatedPercent() . '%';
        $capacity  = $this->formatExtension->formatBytes($poolInfo->getTotalSizeInBytes(), 1);

        $displayModel = $this->deviceConfig->getDisplayModel();

        $replacements = [
            '-model' => $displayModel,
            '-sn' => strtoupper($this->serial->get()),
            '-freespace' => $freeSpace,
            '-usedspace' => $usedspace,
            '-percentused' => $percentused,
            '-capacity' => $capacity,
            '-datetime' => $this->dateTime->getDate('d/m/y g:i:sa'),
            '-unixtime' => $this->dateTime->getTime(),
            '-devicehostname' => $this->hostname->get()
        ];

        if (!empty($hostname)) {
            $agentConfig = $this->agentConfigFactory->create($hostname);

            $asset = $this->assetService->get($hostname);
            $lastPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
            $latestRecoveryPoint = $lastPoint ? $lastPoint->getEpoch() : 0;

            $info = $agentConfig->getAgentInfoOrPairingInfo();

            // Get all possible value replacements - order does matter, avoid recursion
            $replacements = array_merge(
                $replacements,
                [
                    '-agenthostname' => $info['hostname'],
                    '-fqdn' => $info['fqdn'] ?? '',
                    '-agentIP' => $agentConfig->getFullyQualifiedDomainName(),
                    '-os' => $info['os'] ?? '',
                    '-ver' => $info['version'],
                    '-agentused' => $info['localUsed'],
                    '-agentsnaps' => $info['usedBySnaps'],
                    '-last' => $this->dateTime->getDate('d/m/y g:i:sa', $latestRecoveryPoint),
                ]
            );
        }

        // Separate the keys and values
        $replacementKeys = array_keys($replacements);
        $replacementValues = array_values($replacements);

        // Do ONE replacement rather than multiple
        return str_replace($replacementKeys, $replacementValues, $string);
    }
}
