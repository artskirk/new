<?php

namespace Datto\Asset;

use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service class to interface with the retentionChangesJSON asset key file.
 *
 * NOTE:
 *  This service/key file is only used when setting retention for the first time.
 *  It is never updated if retention settings are changed after pairing.
 *  TODO: This issue should be corrected with https://kaseya.atlassian.net/browse/BCDR-17667
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class RetentionChangeService
{
    const RETENTION_CHANGES_KEY_FILE = 'retentionChangesJSON';
    const LOCAL_INDEX = 'local';
    const OFFSITE_INDEX = 'offsite';
    const DEFAULT_INDEX = 0;
    const LEGACY_OVERAGES_KEY_FILE = 'useOldOverages';

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Returns the list of changes to retention for the given asset.
     * If no changes exist, returns an empty array.
     *
     * @param string $keyName Asset key name
     * @return array Retention changes with the following structure
     *   - 'local|offsite':
     *     - $epoch:
     *       - 'offsite': bool True if the change applied to offsite retention settings, False if local.
     *       - 'daily': int Retention duration for daily snapshots
     *       - 'weekly': int Retention duration for weekly snapshots
     *       - 'monthly': int Retention duration for monthly snapshots
     *       - 'keep': int Maximum retention duration for any snapshot
     */
    public function get(string $keyName): array
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);
        $contents = $agentConfig->get(self::RETENTION_CHANGES_KEY_FILE);

        $retentionChanges = json_decode($contents, true);
        return is_array($retentionChanges) ? $retentionChanges : [];
    }

    /**
     * Record a change in local retention settings.
     *
     * @param string $keyName Asset key name
     * @param Retention $retention New local retention settings
     */
    public function recordLocalChange(string $keyName, Retention $retention): void
    {
        $this->add($keyName, $retention, false);
    }

    /**
     * Record a change in offsite retention settings.
     *
     * @param string $keyName Asset key name
     * @param Retention $retention New offsite retention settings
     */
    public function recordOffsiteChange(string $keyName, Retention $retention): void
    {
        $this->add($keyName, $retention, true);
    }

    /**
     * Generates log entries based on changes of retention values.
     *
     * @param Retention $newRetention
     * @param Retention $oldRetention
     * @param bool $isOffsite
     * @param DeviceLoggerInterface $logger
     */
    public function logRetentionChange(
        Retention $newRetention,
        Retention $oldRetention,
        bool $isOffsite,
        DeviceLoggerInterface $logger
    ): void {
        $baseLog = $isOffsite ? 1600 : 1400;
        $retentionType = $isOffsite ? 'offsite' : 'local';

        if (RemoteWebService::isRlyRequest()) {
            $logger->info(sprintf( // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
                'RET%d %s Retention Settings: User has logged in via remote web...',
                $baseLog + 10,
                ucfirst($retentionType)
            ));
        } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] === '127.0.0.1') {
            $logger->info(sprintf( // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
                'RET%d User is running change %s settings via the web interface via ' .
                'localhost (Physically or via VNC)',
                $baseLog + 20,
                $retentionType
            ));
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $logger->info(sprintf( // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
                'RET%d User is running change %s settings via the web interface via ' .
                'using IP address: %s',
                $baseLog + 30,
                $retentionType,
                $_SERVER['REMOTE_ADDR']
            ));
        } else {
            $logger->info(sprintf( // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
                'RET%d User is running change %s settings via the web interface via ' .
                'command line at: %s',
                $baseLog + 40,
                $retentionType,
                gethostbyname(gethostname())
            ));
        }

        $changes = [];
        if ($newRetention->getDaily() !== $oldRetention->getDaily()) {
            $changes['daily']['from'] = $oldRetention->getDaily();
            $changes['daily']['to'] = $newRetention->getDaily();
            $changes['daily']['value'] = 2;
        }
        if ($newRetention->getWeekly() !== $oldRetention->getWeekly()) {
            $changes['weekly']['from'] = $oldRetention->getWeekly();
            $changes['weekly']['to'] = $newRetention->getWeekly();
            $changes['weekly']['value'] = 2;
        }
        if ($newRetention->getMonthly() !== $oldRetention->getMonthly()) {
            $changes['monthly']['from'] = $oldRetention->getMonthly();
            $changes['monthly']['to'] = $newRetention->getMonthly();
            $changes['monthly']['value'] = 2;
        }
        if ($newRetention->getMaximum() !== $oldRetention->getMaximum()) {
            $changes['keep']['from'] = $oldRetention->getMaximum();
            $changes['keep']['to'] = $newRetention->getMaximum();
            $changes['keep']['value'] = 2;
        }

        foreach ($changes as $key => $change) {
            $logger->info(sprintf( // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
                'RET%d User has changed %s retention setting %s from: %d to %d',
                $baseLog + 50 + $change['value'],
                $retentionType,
                $key,
                $change['from'],
                $change['to']
            ));
        }
    }

    /**
     * Add a retention object to the retention change key file.
     *
     * @param string $keyName Asset key name
     * @param Retention $retention New retention settings
     * @param bool $isOffsite True if the retention settings are for OffsiteRetention, False otherwise
     */
    private function add(string $keyName, Retention $retention, bool $isOffsite): void
    {
        $retentionChanges = $this->get($keyName);
        if (empty($retentionChanges)) {
            $retentionChanges = $this->buildDefault();
        }

        $index = $isOffsite ? self::OFFSITE_INDEX : self::LOCAL_INDEX;
        $epoch = $this->dateTimeService->getTime();

        $retentionChanges[$index][$epoch] = [
            'offsite' => $isOffsite,
            'daily' => $retention->getDaily(),
            'weekly' => $retention->getWeekly(),
            'monthly' => $retention->getMonthly(),
            'keep' => $retention->getMaximum()
        ];

        $this->set($keyName, $retentionChanges);
    }

    /**
     * Set the contents of the retention change key file.
     *
     * @param string $keyName Asset key name
     * @param array $retentionChanges Structured retention changes array
     */
    private function set(string $keyName, array $retentionChanges): void
    {
        $encoded = json_encode($retentionChanges);

        $agentConfig = $this->agentConfigFactory->create($keyName);
        $agentConfig->set(self::RETENTION_CHANGES_KEY_FILE, $encoded);
    }

    /**
     * Creates a structured array of retention changes with default values.
     *
     * @return array Structured default retention changes array
     */
    private function buildDefault(): array
    {
        $localDefaults = $this->getLocalRetentionDefaults();
        $offsiteDefaults = $this->getOffsiteRetentionDefaults();

        $retentionChangesDefault = [
            self::LOCAL_INDEX => [
                self::DEFAULT_INDEX => [
                    'offsite' => false,
                    'daily' => $localDefaults->getDaily(),
                    'weekly' => $localDefaults->getWeekly(),
                    'monthly' => $localDefaults->getMonthly(),
                    'keep' => $localDefaults->getMaximum()
                ]
            ],
            self::OFFSITE_INDEX => [
                self::DEFAULT_INDEX => [
                    'offsite' => true,
                    'daily' => $offsiteDefaults->getDaily(),
                    'weekly' => $offsiteDefaults->getWeekly(),
                    'monthly' => $offsiteDefaults->getMonthly(),
                    'keep' => $offsiteDefaults->getMaximum()
                ]
            ]
        ];

        return $retentionChangesDefault;
    }

    /**
     * Get the legacy default settings for local retention.
     *
     * The default values specified here are carry-overs from when the retention class existed in web/
     * TODO: Resolve the discrepancy between these default values and the defaults values in Retention.php
     *
     * @return Retention Default local retention settings
     */
    private function getLocalRetentionDefaults(): Retention
    {
        $daily = 120;
        $weekly = 168;
        $monthly = Retention::NEVER_DELETE;
        $maximum = 1488;
        return new Retention($daily, $weekly, $monthly, $maximum);
    }

    /**
     * Get the legacy default settings for offsite retention.
     *
     * The default values specified here are carry-overs from when the retention class existed in web/
     * TODO: Resolve the discrepancy between these default values and the defaults values in Retention.php
     *
     * @return Retention Default offsite retention settings
     */
    private function getOffsiteRetentionDefaults(): Retention
    {
        $useOldRetention = $this->deviceConfig->has(self::LEGACY_OVERAGES_KEY_FILE)
            ? (bool)$this->deviceConfig->get(self::LEGACY_OVERAGES_KEY_FILE)
            : true;

        $daily = Retention::NEVER_DELETE;
        $weekly = Retention::NEVER_DELETE;
        $monthly = Retention::NEVER_DELETE;
        $maximum = Retention::NEVER_DELETE;
        $oldOffsite = new Retention($daily, $weekly, $monthly, $maximum);

        return $useOldRetention ? $oldOffsite : $this->getLocalRetentionDefaults();
    }
}
