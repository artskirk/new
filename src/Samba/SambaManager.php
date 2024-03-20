<?php

namespace Datto\Samba;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Utility\Filesystem;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Manages ALL samba configuration files and adjustments.
 *
 * @author Evan Buther <evan.buther@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class SambaManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The origin of the SAMBA configuration
     */
    public const SAMBA_CONF_FILE = '/etc/samba/smb.conf';

    /**
     * The origin of DEVICE-SPECIFIC (namely shares) SAMBA configuration
     */
    public const DEVICE_CONF_FILE = '/datto/config/sambaConfig';

    /**
     * The origin of DATTO-SPECIFIC SAMBA configuration. This file should contain settings that are consistent across
     * all devices whereas DEVICE_CONF_FILE contains settings specific to an individual device.
     */
    public const DATTO_CONF_FILE = '/etc/samba/datto-smb.conf';

    /**
     * @var SambaFile[] File stack of configuration files
     */
    protected array $configFileStack = [];

    private ProcessFactory $processFactory;
    private UserService $userService;
    private Filesystem $filesystem;
    private PosixHelper $posixHelper;

    /**
     * Constructor for the manager, loads the existing nested samba configuration
     */
    public function __construct(
        ProcessFactory $processFactory,
        UserService $userService,
        Filesystem $filesystem,
        PosixHelper $posixHelper
    ) {
        $this->processFactory = $processFactory;
        $this->userService = $userService;
        $this->filesystem = $filesystem;
        $this->posixHelper = $posixHelper;

        $this->readConf(self::SAMBA_CONF_FILE);
    }

    /**
     * Set the SMB server's minimum protocol version. If already set to the specified version, this is a noop.
     * @param int $version the version to set; 1 or 2.
     * @return bool true if it did set it, false otherwise.
     */
    public function setServerProtocolMinimumVersion(int $version): bool
    {
        $this->logger->info('SMB0009 Setting server min protocol', ['version' => $version]);

        $globalSection = $this->getSectionByName('global', false, self::DATTO_CONF_FILE);
        if ($globalSection == null) {
            $this->addInclude(self::DATTO_CONF_FILE, self::SAMBA_CONF_FILE);
            $globalSection = $this->getSectionByName('global', false, self::DATTO_CONF_FILE);
            if (!$globalSection) {
                throw new Exception('Failed to get global section from ' . self::DATTO_CONF_FILE);
            }
        }
        $serverMinProtocol = $globalSection->getProperty('server min protocol');
        if (!$serverMinProtocol) {
            throw new Exception('Failed to get \'server min protocol\' from ' . self::DATTO_CONF_FILE);
        }
        switch ($version) {
            case 1:
                if ($serverMinProtocol === 'NT1') {
                    return false;
                }
                $globalSection->setProperty('server min protocol', 'NT1');
                return true;
            case 2:
                if ($serverMinProtocol === 'SMB2') {
                    return false;
                }
                $globalSection->setProperty('server min protocol', 'SMB2');
                return true;
            default:
                return false;
        }
    }

    public function getServerProtocolMinimumVersion(): int
    {
        $globalSection = $this->getSectionByName('global', false, self::DATTO_CONF_FILE);
        if ($globalSection == null) {
            $this->addInclude(self::DATTO_CONF_FILE, self::SAMBA_CONF_FILE);
            $globalSection = $this->getSectionByName('global', false, self::DATTO_CONF_FILE);
            if (!$globalSection) {
                throw new Exception('Failed to get global section from ' . self::DATTO_CONF_FILE);
            }
        }

        $serverMinProtocol = $globalSection->getProperty('server min protocol');
        if (!$serverMinProtocol) {
            throw new Exception('Failed to get \'server min protocol\' from ' . self::DATTO_CONF_FILE);
        }
        switch ($serverMinProtocol) {
            case 'NT1':
                return 1;
            case 'SMB2':
            default:
                return 2;
        }
    }

    /**
     * Returns whether SMB signing is required.
     */
    public function isSigningRequired(): bool
    {
        $globalSection = $this->getSectionByName('global');
        // If 'server signing' is not specified, it defaults to 'default', which means signing is not required.
        if ($globalSection === null) {
            return false;
        }
        return $globalSection->getProperty('server signing') === 'mandatory';
    }

    /**
     * Sets whether SMB signing is required.
     */
    public function updateSigningRequired(bool $isRequired): void
    {
        $globalSection = $this->getSectionByName('global');
        if ($globalSection === null) {
            throw new Exception('Failed to find global section');
        }
        $propertyValue = $isRequired ? 'mandatory' : 'default'; # 'default' means signing is not required
        $globalSection->setProperty('server signing', $propertyValue);
    }

    /**
     * Returns all users listed in all shares.
     *
     * @return string[] $allUsers  All users listed in all shares.
     */
    public function listAllShareUsers(): array
    {
        $allShares = $this->getAllShares();

        $allUsers = [];
        foreach ($allShares as $share) {
            $allShareUsers = $share->getAllUsers();

            $allUsers = array_merge($allUsers, $allShareUsers);
        }

        return $allUsers;
    }

    /**
     * Removes a user (or group) from all shares
     *
     * @param string $username The user or group to be removed
     * @return bool  Whether the user was removed from any shares (may not have been if not assigned)
     */
    public function removeUserFromAllShares(string $username): bool
    {
        $allShares = $this->getAllShares();

        $userRemoved = false;

        foreach ($allShares as $share) {
            if ($share->removeUser($username)) {
                $userRemoved = true;
            }
        }

        return $userRemoved;
    }

    public function reload(): void
    {
        $this->readConf(self::SAMBA_CONF_FILE);
    }

    /**
     * Creates a new samba share and creates a separate configuration file (if given)
     *
     * @param string $shareName The name of the share to be created
     * @param string $sharePath The path of the share to be created
     * @param string $shareFilePath The full path of the file the configuration should be added to
     * @return SambaShare The created share object
     */
    public function createShare(
        string $shareName,
        string $sharePath,
        string $shareFilePath = self::DEVICE_CONF_FILE
    ): SambaShare {
        $this->logger->debug('SMB0001 Creating Samba share', ['shareName' => $shareName, 'sharePath' => $sharePath]);
        // WARNING: This will wipe out any unsynced changes!
        $this->clearLoadedConfigSectionsAndReload();
        if ($this->doesShareExist($shareName)) {
            throw new Exception('A share with the name \'' . $shareName . '\' already exists.');
        }

        $newShare = new SambaShare($shareName, $this->userService, $this->filesystem);
        $newShare->setPath($sharePath);

        $includedFiles = $this->listConfigFilePaths();

        if (in_array($shareFilePath, $includedFiles)) {
            foreach ($this->configFileStack as $sambaFile) {
                if ($sambaFile->getFilePath() == $shareFilePath) {
                    $sambaFile->addSection($newShare);
                    break;
                }
            }
        } else {
            $newConfigFile = new SambaFile($shareFilePath, $this->filesystem, $this->userService);
            $newConfigFile->addSection($newShare);

            $includeTo = self::SAMBA_CONF_FILE;
            if ($newConfigFile->getFilePath() != self::DEVICE_CONF_FILE) {
                if (!in_array(self::DEVICE_CONF_FILE, $includedFiles)) {
                    $deviceConfigFile = new SambaFile(self::DEVICE_CONF_FILE, $this->filesystem, $this->userService);

                    foreach ($this->configFileStack as $sambaFile) {
                        if ($sambaFile->getFilePath() == self::SAMBA_CONF_FILE) {
                            $sambaFile->addInclude($deviceConfigFile);
                            $this->configFileStack[] = $deviceConfigFile;
                            break;
                        }
                    }
                }

                $includeTo = self::DEVICE_CONF_FILE;
            }

            foreach ($this->configFileStack as $sambaFile) {
                if ($sambaFile->getFilePath() == $includeTo) {
                    $sambaFile->addInclude($newConfigFile);
                    $this->configFileStack[] = $newConfigFile;
                    break;
                }
            }
        }

        return $newShare;
    }

    /**
     * Adds an include line to the Samba config file
     *
     * @param string $newIncludePath The file path to be added as an include line
     * @param string $includeTo The file path where the new line should be added to
     */
    public function addInclude(string $newIncludePath, string $includeTo): void
    {
        $newConfigFile = new SambaFile($newIncludePath, $this->filesystem, $this->userService);

        foreach ($this->configFileStack as $sambaFile) {
            if ($sambaFile->getFilePath() === $includeTo) {
                $sambaFile->addInclude($newConfigFile);
                break;
            }
        }
    }

    /**
     * Removes an include line from the Samba config file, as well as the file the include was pointing to
     *
     * @param string $includeToRemove The file path to be removed from the config file
     */
    public function removeInclude(string $includeToRemove): void
    {
        foreach ($this->configFileStack as $sambaFile) {
            if ($sambaFile->getFilePath() === $includeToRemove && count($sambaFile->getSections()) > 0) {
                $section = $sambaFile->getSections()[0];
                $section->markForRemoval();
                break;
            }
        }
    }

    /**
     * Removes an existing samba share
     *
     * @param string $shareName The name of the share to be removed
     * @return bool Whether or not the share was removed
     */
    public function removeShare(string $shareName): bool
    {
        $this->clearLoadedConfigSectionsAndReload();
        if (!$this->doesShareExist($shareName)) {
            return true;
        }

        $this->disconnectUsersFromShare($shareName);

        $shareToBeRemoved = $this->getShareByName($shareName);
        if ($shareToBeRemoved) {
            $shareToBeRemoved->markForRemoval();
        }
        $this->sync();

        return !$this->doesShareExist($shareName);
    }

    /**
     * Remove shares with the given path.
     * @param string $path
     */
    public function removeShareByPath(string $path)
    {
        foreach ($this->getAllShares() as $share) {
            if ($share instanceof SambaShare && $share->getPath() === $path) {
                $this->removeShare($share->getName());
            }
        }
    }

    /**
     * @param string $path
     * @return SambaSection[]
     */
    public function getSharesByPath(string $path): array
    {
        $shares = [];

        foreach ($this->getAllShares() as $share) {
            if ($share instanceof SambaShare && $share->getPath() === $path) {
                $shares[] = $share;
            }
        }

        return $shares;
    }

    /**
     * Gets a list of clients conected to samba shares.
     *
     * @param string|null $shareName optional, share name to get clients for.
     *
     * @return SambaClientInfo[]
     */
    public function getOpenClientConnections(string $shareName = null): array
    {
        $clients = [];

        $process = $this->processFactory->get(['smbstatus', '-S']);

        $process->run();

        if (false === $process->isSuccessful()) {
            return $clients;
        }

        $output = $process->getOutput();
        $lines = explode(PHP_EOL, trim($output));
        $lines = array_slice($lines, 2); // strip header

        foreach ($lines as $line) {
            $columns = preg_split('/\s+/', $line);
            $columns = array_pad($columns, 4, null); // ensure 4 columns

            $clientInfo = new SambaClientInfo(
                trim($columns[0]), // share name
                (int) $columns[1], // smbd pid
                trim($columns[2]), // client machine name
                strtotime($columns[3])  // connected at
            );

            if ($shareName === null) {
                $clients[] = $clientInfo;
            } elseif ($clientInfo->getShareName() === $shareName) {
                $clients[] = $clientInfo;
            }
        }

        return $clients;
    }

    /**
     * Confirms whether a share with a given name exists
     *
     * @param string $shareName The name of the share
     * @return bool  Whether or not the share exists
     */
    public function doesShareExist(string $shareName): bool
    {
        $shareArray = $this->getSectionsBy($shareName, true);
        return !empty($shareArray);
    }

    /**
     * Returns the requested share object
     *
     * @param string $shareName The name of the share to be returned
     * @return SambaShare|null The share object or null
     */
    public function getShareByName(string $shareName): ?SambaShare
    {
        return $this->getSectionByName($shareName, true);
    }

    /**
     * Returns the requested section object
     *
     * @param string $sectionName The name of the section to be returned
     * @param bool $isShare Whether the returned object should be a SambaSection or SambaShare object
     * @param string|null $sambaFilePath Optional specific samba config file path to get the section from
     */
    public function getSectionByName(string $sectionName, bool $isShare = false, string $sambaFilePath = null): ?SambaSection
    {
        $requestedSection = $this->getSectionsBy($sectionName, $isShare, $sambaFilePath);
        return $requestedSection[0] ?? null;
    }

    /**
     * Returns all share objects as an array
     *
     * @return SambaShare[]  All share objects
     */
    public function getAllShares(): array
    {
        return $this->getSectionsBy('*', true);
    }

    /**
     * Returns all section objects (sections & shares) as an array
     *
     * @return SambaSection[]  All section / share objects
     */
    public function getAllSections(): array
    {
        return $this->getSectionsBy('*');
    }

    /**
     * Whether or not the stored configuration is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        /**
         * Currently the function gets all shares, loads them into a single
         * file, and greps 'testparm' to ensure the services file loads
         * appropriately.
         */
        $sectionsString = '';
        foreach ($this->getAllSections() as $section) {
            if ($section->isValid()) {
                $sectionsString .= $section->confOutput();
            }
        }

        $tmpFile = $this->filesystem->tempName('/tmp', 'samba-test-');
        $this->filesystem->filePutContents($tmpFile, $sectionsString);

        $process = $this->processFactory->get(['testparm', '-s', $tmpFile]);
        $process->run();
        $output = $process->getErrorOutput();

        $retVal = strpos($output, "Loaded services file OK") !== false;
        $this->filesystem->unlink($tmpFile);

        return $retVal;
    }

    /**
     * Sync the configuration files and reload samba
     *
     * @param bool $debugOutput If set to TRUE cache files will be created but not moved
     * @return bool Whether or not the sync has taken completed
     */
    public function sync(bool $debugOutput = false): bool
    {
        $this->logger->debug('SMB0002 Syncing/writing Samba config ...');

        if (!$this->isValid()) {
            $this->logger->debug('SMB0003 Config is invalid. Cannot save!');
            return false;
        }

        foreach ($this->configFileStack as $configFile) {
            try {
                $configFile->openAndLock(true);
            } catch (Exception $e) {
                // do nothing, the file may not exist yet
                $this->logger->debug('SMB0008 Unable to lock config file.', ['exception' => $e->getMessage()]);
            }
        }

        //The function, reload, destroys isPublic flag for all shares. Save them and restore after reload.
        $savePublic = [];
        foreach ($this->getAllShares() as $share) {
            $savePublic[$share->getName()] = $share->isPublic;
        }

        $this->reload();

        //Restore isPublic flag for all shares. They were all destroyed by the function, reload.
        foreach ($this->getAllShares() as $share) {
            $share->isPublic = $savePublic[$share->getName()];
        }

        $this->applyDefaultConfiguration();

        while ($configFile = array_pop($this->configFileStack)) {
            if ($configFile->isValid()) {
                $this->logger->debug('SMB0004 - Writing ' . $configFile->getFilePath() . ' ...');
                $configFile->write($debugOutput);
            } else {
                $this->logger->debug('SMB0005 - Deleting INVALID config file ' . $configFile->getFilePath() . ' ...');
                $configFile->delete();
            }
        }

        $reloadProcess = $this->processFactory->get(['service', 'smbd', 'reload']);
        $reloadProcess->run();

        $killProcess = $this->processFactory->get(['killall', '-HUP', 'smbd']);
        $killProcess->run();

        $this->reload();
        return true;
    }

    /**
     * Reads the current configuration file(s) and populates properties
     *
     * @param string $filePath The path of the configuration file to be read
     */
    private function readConf(string $filePath)
    {
        $sambaConf = null;

        foreach ($this->configFileStack as $configFile) {
            if ($configFile->getFilePath() == $filePath) {
                $sambaConf = $configFile;
                break;
            }
        }

        if ($sambaConf != null) {
            $sambaConf->reload();
        } else {
            $sambaConf = new SambaFile($filePath, $this->filesystem, $this->userService);
            $this->configFileStack[] = $sambaConf;
        }

        $includes = $sambaConf->getIncludes();

        foreach ($includes as $include) {
            $this->readConf($include);
        }
    }

    /**
     * Disconnect all users from a given share.
     *
     * @param string $shareName Share name
     */
    private function disconnectUsersFromShare(string $shareName)
    {
        $clients = $this->getOpenClientConnections($shareName);

        foreach ($clients as $client) {
            if ($client->getShareName() === $shareName) {
                // First try with smbcontrol, then posix_kill
                $process = $this->processFactory->get(['smbcontrol', $client->getSmbdPid(), 'close-share', $shareName]);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->logger->info('SMB0006 Failed to disconnect users from share via smbcontrol. Will try posix_kill.', ['shareName' => $shareName]);
                    $result = $this->posixHelper->kill($client->getSmbdPid(), PosixHelper::SIGNAL_TERM);
                    if (!$result) {
                        $this->logger->info('SMB0007 Failed to disconnect users from share via posix_kill.', ['shareName' => $shareName]);
                        throw new Exception('Failed to disconnect users from share.');
                    }
                }
            }
        }
    }

    /**
     * List the full paths of the configuration files
     *
     * @return array  Full paths of configuration files
     */
    private function listConfigFilePaths(): array
    {
        $configPaths = [];

        foreach ($this->configFileStack as $configFile) {
            $configPaths[] = $configFile->getFilePath();
        }

        return $configPaths;
    }

    /**
     * Apply any needed configuration changes to the global Samba config.
     */
    private function applyDefaultConfiguration()
    {
        $globalSection = $this->getSectionByName('global');

        if ($globalSection) {
            $currentValue = $globalSection->getProperty('restrict anonymous');
            if ($currentValue === null) {
                $globalSection->setProperty('restrict anonymous', '1');
            }
        }
    }

    /**
     * Returns an array of the requested section/share objects
     *
     * @param string $sectionAttr The property value of the requested section/share object
     * @param bool $sharesOnly Whether the returned object should be a SambaSection or SambaShare object
     * @param string|null $sambaFilePath Optional specific samba config file path to get the section from
     * @return array  An array of section/share objects
     */
    private function getSectionsBy(string $sectionAttr, bool $sharesOnly = false, string $sambaFilePath = null): array
    {
        $requestedSections = [];

        foreach ($this->configFileStack as $sambaFile) {
            $fileSections = $sambaFile->getSections($sharesOnly);
            if ($sambaFilePath != null && $sambaFilePath !== $sambaFile->getFilePath()) {
                continue;
            }
            foreach ($fileSections as $fileSection) {
                if ($fileSection->getName() == $sectionAttr || $sectionAttr == '*') {
                    $requestedSections[] = $fileSection;
                }
            }
        }

        return $requestedSections;
    }

    /**
     * Clear all loaded sections from all config files
     */
    private function clearLoadedConfigSectionsAndReload()
    {
        foreach ($this->configFileStack as $file) {
            $file->clearLoadedSections();
        }
        $this->reload();
    }
}
