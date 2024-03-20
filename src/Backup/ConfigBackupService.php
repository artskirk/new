<?php

namespace Datto\Backup;

use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\Serializer\LegacyRetentionSerializer;
use Datto\Billing\Service as BillingService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\ServerNameConfig;
use Datto\Dataset\DatasetFactory;
use Datto\Dataset\ZFS_Dataset;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LogArchiver;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\System\StateChecker;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Cloud\RemoteDestroyException;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Class ConfigBackupService
 *
 * This class implements logic to copy config files from /datto/config/ and
 * /etc/ to /home/configBackup/. It also implements logic to move rotated
 * log files from /var/log/, /datto/config/keys/, etc. to /home/configBackup/.
 *
 * Source directory tree is mirrored within /datto/configBackup/. For example,
 * files copied from /etc/ will be in /home/configBackup/files/etc/.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class ConfigBackupService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const LOCK_FILE = '/dev/shm/configBackup.lock';
    const KEYS_DIR = '/datto/config/keys/';
    const LOCAL_RETENTION = '4464:4464:4464:4464'; // 4464 hours <=> 6 months
    const OFFSITE_RETENTION = '26208:26208:26208:26208'; // 26208 hours <=> 3 years

    /** @var string $dataset Name of ZFS dataset to which data is to backed-up. */
    private $dataset;

    /** @var string $zfsPath */
    private $zfsPath;

    /** @var string Path to .offsiteControl file. */
    private $offsiteControlFile;

    private ProcessFactory $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var SpeedSync */
    private $speedSync;

    /** @var ZFS_Dataset */
    private $zfsDataset;

    /** @var LogArchiver */
    private $logArchiver;

    /** @var StateChecker */
    private $stateChecker;

    /** @var BillingService */
    private $billingService;

    /** @var LegacyRetentionSerializer */
    private $legacyRetentionSerializer;

    /** @var FeatureService */
    private $featureService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Lock */
    private $lock;

    /** @var Collector */
    private $collector;

    private ServerNameConfig $serverNameConfig;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        SpeedSync $speedSync,
        DatasetFactory $datasetFactory,
        LogArchiver $logArchiver,
        StateChecker $stateChecker,
        BillingService $billingService,
        LegacyRetentionSerializer $legacyRetentionSerializer,
        FeatureService $featureService,
        Collector $collector,
        LockFactory $lockFactory,
        ServerNameConfig $serverNameConfig,
        DeviceConfig $deviceConfig,
        string $destinationDatasetName = 'configBackup'
    ) {
        $this->dataset = $destinationDatasetName;
        $this->zfsPath = 'homePool/home/' . $this->dataset;
        $this->zfsDataset = $datasetFactory->createZfsDataset($this->zfsPath);
        $this->offsiteControlFile = self::KEYS_DIR . $this->dataset . '.offsiteControl';

        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->speedSync = $speedSync;
        $this->logArchiver = $logArchiver;
        $this->stateChecker = $stateChecker;
        $this->billingService = $billingService;
        $this->legacyRetentionSerializer = $legacyRetentionSerializer;
        $this->featureService = $featureService;
        $this->collector = $collector;
        $this->lock = $lockFactory->getProcessScopedLock(self::LOCK_FILE);
        $this->serverNameConfig = $serverNameConfig;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Backs up configs to configBackup but do not take a snapshot. The purpose of this is to have a copy
     * of the asset key files n such on the array in the event of a failed OS drive.
     *
     * We purposefully do not snapshot the dataset because at the frequency we want to do this backup, the
     * number of snapshots would grow quite large.
     *
     * TODO: once we start offsiting configBackup snapshots on cloud devices, we can consider having move snapshots
     * stored locally.
     */
    public function partialBackup()
    {
        $this->logger->setAssetContext($this->dataset);
        $this->logger->info("CNF3001 Starting partial backup process.");
        $this->logger->debug('CNF3004 This will copy /etc/ and /datto/config/ files to a directory under /home/', ['dataset' => $this->dataset]);

        try {
            $this->logger->debug("CNF3006 Acquiring lock ...");
            $this->lock->exclusive();

            $this->preflight();
            $this->prepare();
            $this->backupSystemAndDattoConfigs();

            $this->logger->debug("CNF3002 Backup process completed successfully.");
        } catch (\Throwable $e) {
            $this->collector->increment(Metrics::CONFIG_BACKUP_PARTIAL_FAILED);
            $this->logger->critical("CNF5001 Partial config backup failed", ['exception' => $e]);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Copies config files from /etc/ and /datto/config/ to /home/configBackup/.
     * Also, moves rotated logs from /var/log/ and /datto/config/keys/ to
     * /home/configBackup/.
     *
     * Before copying or moving files to /home/configBackup/, it creates the
     * appropriate directory tree within /home/configBackup/ such that it
     * mirrors the source directory tree.
     */
    public function backup()
    {
        $this->logger->setAssetContext($this->dataset);
        try {
            $this->logger->debug("CNF3006 Acquiring lock ...");
            $this->lock->exclusive();

            $this->doBackup();
            $this->logger->debug("CNF2016 Backup process completed successfully.");
        } catch (\Throwable $e) {
            $this->collector->increment(Metrics::CONFIG_BACKUP_FAILED);
            $this->logger->critical('CNF5001 Config backup failed', ['exception' => $e]);
        } finally {
            $this->lock->unlock();
        }
    }

    private function doBackup()
    {
        $this->logger->info("CNF2001 Starting backup process.");
        $this->logger->debug('CNF3003 This will copy /etc/ and /datto/config/ files and move log files to a directory under /home', ['dataset' => $this->dataset]);

        $this->preflight();
        $this->prepare();

        // Fetch snapshot schedule
        $snapshotSchedule = json_decode($this->filesystem->fileGetContents($this->offsiteControlFile), true);

        // Add dataset to speedsync if it's not already in there
        if ($this->featureService->isSupported(FeatureService::FEATURE_CONFIG_BACKUP_OFFSITE)) {
            $this->logger->debug("CNF2005 Adding ZFS dataset to speedsync if it's not already included.");
            if (!$this->speedSync->isZfsDatasetIncluded($this->zfsPath)) {
                $this->speedSync->add($this->zfsPath, SpeedSync::TARGET_CLOUD);
            }
        } else {
            $this->logger->debug("CNF2021 Offsiting configBackup on this device is disabled, not adding to speedsync.");
        }

        $this->backupSystemAndDattoConfigs();

        // Dump device status info into /home/configBackup/
        $destinationDir = sprintf('/home/%s/state/', $this->dataset);
        $this->logger->debug('CNF2010 Dumping device state info', ['destination' => $destinationDir]);
        $this->dumpDeviceState($destinationDir);

        // Move logs to /home/configBackup/
        $destinationDir = sprintf('/home/%s/files', $this->dataset);
        $this->logger->debug('CNF2011 Moving logs files', ['destination' => $destinationDir]);
        $this->logArchiver->archive($destinationDir);

        // Delete any legacy files/directories from /home/configBackup/
        $this->logger->debug('CNF2022 Deleting any legacy files/directories under /home', ['dataset' => $this->dataset]);
        $this->deleteLegacyFilesAndDirs();

        // Create a new ZFS snapshot
        $currentTimestamp = time();
        $this->logger->debug('CNF2012 Creating a new ZFS snapshot', ['timestamp' => $currentTimestamp]);
        $this->zfsDataset->takeSnapshot($currentTimestamp);

        if ($this->featureService->isSupported(FeatureService::FEATURE_CONFIG_BACKUP_OFFSITE)) {
            // Report the new snapshot to the webserver
            $this->logger->debug('CNF2013 Reporting new snapshot to web server.', ['timestamp' => $currentTimestamp]);
            $this->reportNewSnapshotToWebServer($currentTimestamp);

            // Send the latest snapshot (as well as all previously-unsent snapshots) offsite
            $this->logger->debug("CNF2014 Sending snapshots offsite.");
            $this->sendUnsentSnapshotsOffsite();
        }

        // Save the latest snapshot timestamp to file
        $this->logger->debug("CNF2015 Saving latest snapshot times to .offsiteControl file.");
        $snapshotSchedule['latestSnapshot'] = $currentTimestamp;
        $snapshotSchedule['latestOffsiteSnapshot'] = $currentTimestamp;
        $this->filesystem->filePutContents($this->offsiteControlFile, json_encode($snapshotSchedule));

        if ($this->featureService->isSupported(FeatureService::FEATURE_CONFIG_BACKUP_OFFSITE)) {
            // Get critical points for retention
            $this->logger->debug("CNF2033 Fetching critical points for retention.");
            try {
                $criticalPoints = $this->speedSync->getCriticalPoints($this->zfsPath);
                $criticalPointsStr = implode(',', $criticalPoints);
                $this->logger->debug("CNF2034 Critical points are [$criticalPointsStr].");
            } catch (Exception $exception) {
                $this->logger->critical("CNF6012 Error: Could not fetch critical points. Exiting.");
                throw $exception;
            }
        } else {
            $criticalPoints = [];
        }

        // Set local retention
        $localRetentionFile = self::KEYS_DIR . $this->dataset . ".retention";
        $this->logger->debug('CNF2023 Setting local retention', ['localRetention' => self::LOCAL_RETENTION]);
        $this->filesystem->filePutContents($localRetentionFile, self::LOCAL_RETENTION);

        // Run local retention
        $this->logger->debug("CNF2006 Running local retention.");
        $this->runLocalRetention($criticalPoints);

        if ($this->featureService->isSupported(FeatureService::FEATURE_CONFIG_BACKUP_OFFSITE)) {
            // Set offsite retention
            $offsiteRetentionFile = self::KEYS_DIR . $this->dataset . ".offsiteRetention";
            $this->logger->debug('CNF2024 Setting offsite retention', ['offsiteRetention' => self::OFFSITE_RETENTION]);
            $this->filesystem->filePutContents($offsiteRetentionFile, self::OFFSITE_RETENTION);

            // Run offsite retention
            $this->logger->debug("CNF2007 Running offsite retention.");
            $this->runOffsiteRetention($criticalPoints);
        }
    }

    /**
     * Run any preflight checks to ensure this system is capable of backing up to configBackup.
     */
    private function preflight()
    {
        $this->logger->debug("CNF3005 Running preflights checks.");

        // Check if device is still in service
        $this->logger->debug("CNF2025 Checking if device is in service.");
        if ($this->billingService->isOutOfService()) {
            $message = "Error: Device is out of service (service expired over 30 days ago). " .
                "Configs and rotated logs will not be backed-up. Exiting.";
            $this->logger->critical("CNF6006 $message");
            throw new Exception($message);
        }
    }

    /**
     * Prepare the system to backup to configBackup.
     */
    private function prepare()
    {
        // Make sure offsite settings file is available. If it's not available,
        // create it using default values.
        if (!$this->filesystem->exists($this->offsiteControlFile)) {
            $this->logger->debug('CNF2002 Offsite control file is missing, so creating it using default values.');
            $settings = array(
                'interval' => "0",
                'latestSnapshot' => "0",
                'latestOffsiteSnapshot' => "0",
                'priority' => "2"
            );
            $this->filesystem->filePutContents($this->offsiteControlFile, json_encode($settings));
        }

        // Create ZFS dataset (the called method checks if the dataset already exists)
        $this->logger->debug("CNF2004 Creating ZFS dataset if it doesn't already exist.");
        $this->zfsDataset->create();

        // Make sure ZFS dataset is mounted
        $this->logger->debug('CNF2026 Mounting ZFS dataset if it is not already mounted.', ['dataset' => $this->zfsPath]);
        try {
            if ($this->zfsDataset->mount() !== true) {
                throw new Exception("ZFS dataset $this->zfsPath could not be mounted.");
            }
        } catch (Exception $exception) {
            $this->logger->critical('CNF6007 Error: ZFS mount failure', ['exception' => $exception]);
            throw $exception;
        }
    }

    /**
     * Backup configs to configBackup.
     */
    private function backupSystemAndDattoConfigs()
    {
        // Copy configs from various dirs to /home/configBackup/
        // Each $srcDir entry has source path and cc4pcOnly flag
        $srcDirs = [
            ['/etc/', false],
            ['/datto/config/', false],
            ['/var/lib/datto/', false],
            ['/var/lib/filebeat/', true]
        ];
        foreach ($srcDirs as $srcDir) {
            $srcPath = $srcDir[0];
            $cc4pcOnly = $srcDir[1];
            $destinationDir = sprintf('/home/%s/files%s', $this->dataset, $srcPath);
            $this->logger->debug(
                'CNF2008 Copying configs from ' . $srcPath . ' to a directory under /home',
                ['destination' => $destinationDir]
            );
            if (!$cc4pcOnly || $this->deviceConfig->isCloudDevice()) {
                $this->copyConfigs($srcPath, $destinationDir);
            }
        }
    }

    /**
     * Dumps device state to the given destination directory.
     *
     * @param string $destinationDir Path to the destination directory.
     */
    private function dumpDeviceState($destinationDir)
    {
        // Create destination directory if it doesn't already exist
        if (!$this->filesystem->exists($destinationDir) &&
            !$this->filesystem->mkdir($destinationDir, true, 0755)
        ) {
            $this->logger->critical('CNF6002 Error: Could not create destination directory', ['directory' => $destinationDir]);
            throw new Exception(sprintf(
                "Error: Could not create destination directory '%s'.",
                $destinationDir
            ));
        }

        // Fetch device status array and dump each status into a separate file
        $results = $this->stateChecker->getDeviceStatus();
        foreach ($results as $commandIdentifier => $result) {
            $path = $destinationDir . $commandIdentifier . '.dump';
            $output = sprintf(
                'Command line: %s' . PHP_EOL .
                'Success: %s' . PHP_EOL .
                'Output: ' . PHP_EOL . '%s',
                $result->getCommandLine(),
                json_encode($result->isSuccessful()), // json_encode converts boolean true to string "true"
                $result->getCommandOutput()
            );
            $this->filesystem->filePutContents($path, $output);
        }
    }

    /**
     * Copies config files from the given source directory to the given
     * destination directory. The destination directory will be created
     * if it does not already exist.
     *
     * @param string $sourceDir Path to the source directory with a
     * trailing '/'.
     * @param string $destinationDir Path to the destination directory.
     */
    private function copyConfigs($sourceDir, $destinationDir)
    {
        // If source directory does not exist, just return
        if (!$this->filesystem->exists($sourceDir)) {
            $this->logger->warning('CNF4001 Source directory does not exist. Skipping this copy process.', ['sourceDirectory' => $sourceDir]);
            return;
        }

        // Create destination directory if it doesn't already exist
        if (!$this->filesystem->exists($destinationDir) &&
            !$this->filesystem->mkdir($destinationDir, true, 0755)
        ) {
            $this->logger->critical("CNF6002 Error: Could not create destination directory", ['directory' => $destinationDir]);
            throw new Exception(sprintf(
                "Error: Could not create destination directory '%s'.",
                $destinationDir
            ));
        }

        // Delete any looped paths from destination dir
        //
        // Note: Getting the loops from the source dir instead of destination dir
        // because 'find' command is not able to detect loops in the destination
        // (/home/configBackup/), perhaps because it's a ZFS filesystem.
        $loopedPaths = $this->getLoopedPaths($sourceDir);
        if (!empty($loopedPaths)) {
            $this->logger->info('CNF2039 Deleting any filesystem loops in destination directory', ['destination' => $destinationDir]);
            foreach ($loopedPaths as $loopedPath) {
                // Remove source dir part from the beginning of the loop path
                $loopedPath = preg_replace("#^$sourceDir#", '', $loopedPath);

                // Create the full loop path in destination and delete that path
                $loopedPathFull = sprintf("%s/%s", $destinationDir, $loopedPath);
                if (!$this->filesystem->exists($loopedPathFull)) {
                    continue;
                }
                try {
                    $this->filesystem->unlinkDir($loopedPathFull, 5400); // 5400 secs = 1 hr 30 mins
                } catch (Exception $exception) {
                    $this->logger->warning('CNF4002 Error deleting loop. Ignoring this error.', ['loopedPath' => $loopedPathFull]);
                    continue;
                }
                $this->logger->debug('CNF2040 Deleted loop', ['loopedPath' => $loopedPathFull]);
            }
        }

        // Construct command
        $command = ['rsync'];
        $command[] = '-a'; // Archive
        $command[] = '-v'; // Verbose
        $command[] = '-L'; // Copy symlinks
        $command[] = '--delete'; // Delete extraneous files from destination
        $command[] = '--delete-excluded'; // Delete excluded files/dirs as well
        $command[] = '--filter'; // Filter files/dirs matching given pattern
        $command[] = 'exclude *.log'; // Exclude file/dir matching this pattern from getting copied
        $command[] = '--filter'; // Filter files/dirs matching given pattern
        $command[] = 'protect *.log'; // Protect files/dirs matching this pattern from deletion
        foreach ($loopedPaths as $path) {
            // Remove source directory part from the path because rsync uses only relative
            // path with '--exclude' option
            $path = preg_replace("#$sourceDir#", '', $path);

            $command[] = '--exclude'; // Exclude files/dirs matching the given pattern from getting copied
            $command[] = "/$path"; // '/' prefix causes rsync to treat this dir as subdir of source dir
        }
        $command[] = $sourceDir;
        $command[] = $destinationDir;
        $process = $this->processFactory->get($command);
        $this->logger->debug('CNF2017 Copy command', ['commandLine' => $process->getCommandLine()]);

        // Run command
        $process->run();
        if (!$process->isSuccessful()) {
            $this->logger->warning("CNF6003 Ignoring copy errors.", ['errorOutput' => $process->getErrorOutput()]);
        }
    }

    /**
     * Reports the given snapshot timestamp to the web server.
     *
     * @param int $snapshot Snapshot timestamp to be reported to the web server.
     */
    private function reportNewSnapshotToWebServer($snapshot)
    {
        $deviceId = intval(rtrim($this->filesystem->fileGetContents('/datto/config/deviceID')));
        $url = sprintf(
            "https://%s/sirisReporting/latestSnapshot.php?deviceID=%s\\&nas=%s\\&time=%s",
            $this->serverNameConfig->getServer(ServerNameConfig::DEVICE_DATTOBACKUP_COM),
            urlencode($deviceId),
            urlencode($this->dataset),
            $snapshot
        );
        $process = $this->processFactory->get(['curl', '--connect-timeout', 30, '--retry', 3, '--max-time', 3600, $url]);
        $this->logger->debug('CNF2018 Curl command', ['command' => $process->getCommandLine()]);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->logger->error('CNF6004 Curl command failed', ['errorOutput' => $process->getErrorOutput()]);
            throw new Exception('Curl command failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * Sends unsent snapshots offsite.
     */
    private function sendUnsentSnapshotsOffsite()
    {
        // Find local snapshots that have not been offsited yet
        $offsitedSnapshots = $this->speedSync->getOffsitePoints($this->zfsPath);
        $queuedSnapshots = $this->speedSync->getQueuedPoints($this->zfsPath);
        $localSnapshots = $this->zfsDataset->listSnapshots();
        $needToOffsiteSnapshots = array_diff($localSnapshots, $offsitedSnapshots, $queuedSnapshots);

        // Offsite each snapshot that has not been offsited yet
        $needToOffsiteSnapshotsStr = implode(',', $needToOffsiteSnapshots);
        $this->logger->debug('CNF2019 Snapshots that will be sent offsite', ['snapshots' => $needToOffsiteSnapshotsStr]);
        foreach ($needToOffsiteSnapshots as $snapshot) {
            $this->speedSync->sendOffsite($this->zfsPath, $snapshot);
        }
    }

    /**
     * Deletes legacy files and directories, except for 'files' and 'state',
     * from /home/configBackup/.
     *
     * The legacy files and directories are those that were created directly
     * in /home/configBackup/ instead of /home/configBackup/files/.
     *
     * In order to avoid repeated attempts to delete legacy files and directories,
     * we'll only delete if /home/configBackup/keys/ directory is present because
     * the presence of this directory indicates legacy structure.
     */
    private function deleteLegacyFilesAndDirs()
    {
        // If /home/configBackup/keys/ does not exist, then that means we don't
        // have the legacy structure any more. In this case, just return.
        if (empty($this->dataset) || !$this->filesystem->isDir("/home/$this->dataset/keys")) {
            $this->logger->debug("CNF2020 Directory does not exist " .
                "or is not a directory, so that means legacy files/directories were already " .
                "removed.", ['directory' => "/home/$this->dataset/keys/"]);
            return;
        }

        // Create an array of file/directory names that should be ignored
        $ignoredFiles = array('files', 'state');

        // Delete legacy files/directories except the ones that are supposed to be
        // ignored
        $files = $this->filesystem->glob("/home/$this->dataset/*");
        foreach ($files as $file) {
            if (in_array($this->filesystem->filename($file), $ignoredFiles)) {
                continue;
            }

            $this->filesystem->unlinkDir($file);
        }
    }

    /**
     * Returns the number of seconds in local retention time.
     *
     * @return int Number of seconds in local retention time.
     */
    private function getLocalRetentionTimeInSeconds()
    {
        $file = self::KEYS_DIR . $this->dataset . '.retention';
        $str = $this->filesystem->fileGetContents($file);
        $hours = $this->legacyRetentionSerializer->unserialize($str)->getMaximum();

        return $hours * 60 * 60;
    }

    /**
     * Runs local retention.
     *
     * Created this method instead of using 'snapctl share:local:retention:run'
     * or 'snapctl retention' because those commands don't seem to work with
     * 'configBackup' share (most probably because this share lacks a .agentInfo
     * file).
     *
     * @param array $criticalSnapshots Array containing critical snapshots. These
     * snapshots should not be deleted.
     */
    private function runLocalRetention($criticalSnapshots)
    {
        // Get all local snapshots except the critical ones
        $snapshots = $this->zfsDataset->listSnapshots();
        $snapshots = array_diff($snapshots, $criticalSnapshots);

        // Determine the timestamp such that any snapshots created before this
        // timestamp can be deleted
        $retentionSeconds = $this->getLocalRetentionTimeInSeconds();
        $startingTimestamp = time() - $retentionSeconds;

        // Remove snapshots that are older than the local retention limit
        foreach ($snapshots as $snapshot) {
            if ($startingTimestamp <= $snapshot) {
                break;
            }

            try {
                $this->logger->debug('CNF2031 Destroying ZFS snapshot', ['snapshot' => $snapshot]);
                $this->zfsDataset->destroySnapshot($snapshot);
            } catch (Exception $exception) {
                $this->logger->critical('CNF6009 Error: ZFS destroy command failed', ['exception' => $exception]);
                throw $exception;
            }
        }
    }

    /**
     * Returns the number of seconds in offsite retention time.
     *
     * @return int Number of seconds in offsite retention time.
     */
    private function getOffsiteRetentionTimeInSeconds()
    {
        $file = self::KEYS_DIR . $this->dataset . '.offsiteRetention';
        $str = $this->filesystem->fileGetContents($file);
        $hours = $this->legacyRetentionSerializer->unserialize($str)->getMaximum();

        return $hours * 60 * 60;
    }

    /**
     * Runs offsite retention on the dataset.
     *
     * @param array $criticalSnapshots Array containing critical snapshots.
     * These snapshots should not be deleted.
     */
    private function runOffsiteRetention($criticalSnapshots)
    {
        // Get all offsite snapshots, except the critical ones, and sort them numerically
        $snapshots = $this->speedSync->getOffsitePoints($this->zfsPath);
        $snapshots = array_diff($snapshots, $criticalSnapshots);
        sort($snapshots, SORT_NUMERIC);

        // Determine the timestamp such that any snapshots created before this
        // timestamp can be deleted
        $retentionSeconds = $this->getOffsiteRetentionTimeInSeconds();
        $startingTimestamp = time() - $retentionSeconds;

        // Remove snapshots that are older than the offsite retention limit
        foreach ($snapshots as $snapshot) {
            if ($startingTimestamp <= $snapshot) {
                break;
            }

            $this->logger->debug('CNF2032 Destroying offsite snapshot', ['snapshot' => $snapshot]);
            try {
                $this->speedSync->remoteDestroy("{$this->zfsPath}@$snapshot", DestroySnapshotReason::RETENTION());
            } catch (RemoteDestroyException $e) {
                $this->logger->warning("CNF6011 Could not destroy offsite snapshot. Ignoring.", ['snapshot' => $snapshot, 'errorCode' => $e->getCode(), 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Returns an array containing symlinks pointing to an ancestor and thereby forming a loop.
     *
     * Note: 'find' command is not able to detect loops in /home/configBackup/, probably because it's
     * a ZFS filesystem. There might be some special flags that we might need to pass
     * to make it work on ZFS, but I haven't been able to determine those flags.
     *
     * @param string $parentDir Path to parent directory in which loops are to be detected.
     * @return array Array containing symlinks that create loops.
     */
    private function getLoopedPaths($parentDir)
    {
        // Construct the command
        $this->logger->debug('CNF2035 Detecting any filesystem loops in parent directory', ['parentDir' => $parentDir]);
        $loopedPaths = array();
        $process = $this->processFactory
            ->get([
                'find',
                '-L', // Follow symlinks
                $parentDir,
                '-mindepth', 15 // Implies that test should not be applied if depth is less than this arbitrarily chosen number.
            ]);
        $this->logger->debug('CNF2036 Loop detection command', ['command' => $process->getCommandLine()]);
        $process->run();

        // Fetch output
        $output = trim($process->getErrorOutput());
        if (empty($output)) {
            $this->logger->debug("CNF2037 No loops detected.");
            return $loopedPaths;
        }

        // Find loops in the output
        //
        // If 'find' detects loops in the filesystem, the stderr output for each detected
        // loop will look like the following:
        // "find: File system loop detected; ‘/datto/config/screenshots/screenshots’ is
        // part of the same file system loop as ‘/datto/config/screenshots’."
        $lines = explode("\n", $output);
        $lines = preg_filter('/^find: File system loop detected; ‘/', '', $lines);
        foreach ($lines as $line) {
            $position = strpos($line, '’ is part of the same file system loop as ');
            $symlink = substr($line, 0, $position);
            $symlink = trim($symlink, " \t\0\x22\x27");
            $loopedPaths[] = $symlink;
        }
        $this->logger->debug('CNF2038 Filesystem loops were detected', ['loopedPaths' => $loopedPaths]);

        return $loopedPaths;
    }
}
