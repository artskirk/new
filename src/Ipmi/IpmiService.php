<?php
namespace Datto\Ipmi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Ipmi\FlashingStages\RestoreSettingsStage;
use Datto\Process\Expect;
use Datto\Security\SecretFile;
use Datto\Ipmi\FlashingStages\BackupStage;
use Datto\Ipmi\FlashingStages\FlashStage;
use Datto\Ipmi\FlashingStages\RegisterStage;
use Datto\System\ModuleManager;
use Datto\System\Transaction\Transaction;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Class IpmiService
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class IpmiService
{
    // keys to access information returned by getLanSettings()
    // dependent on the output format of 'ipmitool lan print 1'
    const LAN_USER_LOCKOUT_INTERVAL = 'User Lockout Interval';
    const LAN_ATTEMPT_COUNT_RESET_INT = 'Attempt Count Reset Int.';
    const LAN_INVALID_PASSWORD_DISABLE = 'Invalid password disable';
    const LAN_BAD_PASSWORD_THRESHOLD = 'Bad Password Threshold';
    const LAN_CIPHER_SUITE_PRIV_MAX = 'Cipher Suite Priv Max';
    const LAN_RMCP_CIPHER_SUITES = 'RMCP+ Cipher Suites';
    const LAN_802_1Q_VLAN_PRIORITY = '802.1q VLAN Priority';
    const LAN_802_1Q_VLAN_ID = '802.1q VLAN ID';
    const LAN_BACKUP_GATEWAY_MAC = 'Backup Gateway MAC';
    const LAN_BACKUP_GATEWAY_IP = 'Backup Gateway IP';
    const LAN_DEFAULT_GATEWAY_MAC = 'Default Gateway MAC';
    const LAN_IP_ADDRESS_SOURCE = 'IP Address Source';
    const LAN_AUTH_TYPE_OEM = 'Auth Type OEM';
    const LAN_AUTH_TYPE_ADMIN = 'Auth Type Admin';
    const LAN_AUTH_TYPE_OPERATOR = 'Auth Type Operator';
    const LAN_SET_IN_PROGRESS = 'Set in Progress';
    const LAN_AUTH_TYPE_SUPPORT = 'Auth Type Support';
    const LAN_AUTH_TYPE_CALLBACK = 'Auth Type Callback';
    const LAN_AUTH_TYPE_USER = 'Auth Type User';
    const LAN_IP_ADDRESS = 'IP Address';
    const LAN_SUBNET_MASK = 'Subnet Mask';
    const LAN_MAC_ADDRESS = 'MAC Address';
    const LAN_SNMP_COMMUNITY_STRING = 'SNMP Community String';
    const LAN_IP_HEADER = 'IP Header';
    const LAN_BMC_ARP_CONTROL = 'BMC ARP Control';
    const LAN_GRATUITOUS_ARP_INTRVL = 'Gratituous ARP Intrvl';  //  (sic) what ipmitool emits
    const LAN_DEFAULT_GATEWAY_IP = 'Default Gateway IP';

    const NETWORK_MODE_STATIC = 'static';
    const NETWORK_MODE_DHCP = 'dhcp';

    const STATIC_ADDRESS = 'Static Address';  // possible value for LAN_IP_ADDRESS_SOURCE
    const DHCP_ADDRESS = 'DHCP Address';      // possible value for LAN_IP_ADDRESS_SOURCE

    const USER_NAME = 'name';          // keys which access columns from 'ipmitool user list 1'
    const USER_CAN_CALLIN = 'callin';
    const USER_CAN_LINK_AUTH = 'linkAuth';
    const USER_CAN_IPMI_MSG = 'ipmiMsg';
    const USER_CHANNEL_PRIV_LIMIT = 'channelPrivLimit';

    const ADMIN_USER_NAME = 'admin'; // lower case for strtolower comp

    const DISABLED_IP = '10.123.123.123';                   // random IP in private range
    const DISABLED_GATEWAY_IP = '0.0.0.0';
    const DISABLED_SUBNET_MASK = '255.255.255.254';

    const IPMITOOL = '/usr/bin/ipmitool';
    const PROCESS_TIMEOUT = 60;

    /** BCMs supported by ipmitool have a max password length of either 16 or 20 */
    const MAX_PASSWORD_LENGTH = 16;

    const LOCK_PATH = '/dev/shm/ipmi.lock';

    /** @var Expect */
    private $expect;

    /** @var SecretFile */
    private $secretFile;

    private ProcessFactory $processFactory;

    /** @var IpmiRegistrar */
    private $registrar;

    /** @var IpmiFlasher */
    private $flasher;

    /** @var IpmiTool */
    private $ipmiTool;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Transaction */
    private $transaction;

    /** @var ModuleManager */
    private $moduleManager;

    /** @var Sleep */
    private $sleep;

    /** @var FeatureService */
    private $featureService;

    /** @var Lock */
    private $lock;

    /** @var RetryHandler */
    private $retryHandler;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Expect $expect,
        SecretFile $secretFile,
        ProcessFactory $processFactory,
        IpmiRegistrar $registrar,
        IpmiFlasher $flasher,
        IpmiTool $ipmiTool,
        DeviceLoggerInterface $logger,
        Transaction $transaction,
        ModuleManager $moduleManager,
        Sleep $sleep,
        FeatureService $featureService,
        LockFactory $lockFactory,
        RetryHandler $retryHandler,
        Filesystem $filesystem
    ) {
        $this->secretFile = $secretFile;
        $this->processFactory = $processFactory;
        $this->expect = $expect;
        $this->registrar = $registrar;
        $this->flasher = $flasher;
        $this->ipmiTool = $ipmiTool;
        $this->logger = $logger;
        $this->transaction = $transaction;
        $this->moduleManager = $moduleManager;
        $this->sleep = $sleep;
        $this->featureService = $featureService;
        $this->lock = $lockFactory->getProcessScopedLock(self::LOCK_PATH);
        $this->retryHandler = $retryHandler;
        $this->filesystem = $filesystem;
    }

    /**
     * Check if an IPMI update is available.
     *
     * @return bool
     */
    public function isUpdateAvailable(): bool
    {
        return $this->flasher->isFlashingNeeded();
    }

    /**
     * Update the IPMI firmware if needed.
     *
     * @param bool $backup
     */
    public function updateIfNeeded(bool $backup = true)
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_IPMI_UPDATE);

        if (!$this->flasher->isFlashingNeeded()) {
            $this->logger->info('IPM0008 IPMI update is not needed.');
            return;
        }

        $this->update($backup);
    }

    /**
     * Update the IPMI firmware.
     *
     * @param bool $backup
     */
    public function update(bool $backup = true)
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_IPMI_UPDATE);

        $this->logger->info('IPM0009 Performing IPMI firmware update ...');

        $this->acquireLock();

        try {
            $this->transaction->clear()
                ->add(new RegisterStage($this->logger, $this->moduleManager, $this->registrar))
                ->addIf($backup, new BackupStage($this->logger, $this->flasher, $this->sleep))
                ->add(new FlashStage($this->logger, $this->flasher, $this->ipmiTool, $this->sleep))
                ->add(new RestoreSettingsStage($this->logger, $this, $this->getCurrentSettings()));

            $this->transaction->commit();
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Check if IPMI is registered with device-web.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return $this->registrar->isRegistered();
    }

    /**
     * Register the IPMI of this device with device-web. Once registered, the BMC must be flashed within
     * an allotted time period in order for it to checkin and grab the temporarily-available secret key.
     */
    public function register()
    {
        $this->registrar->register();
    }

    /**
     * Unregister the IPMI of this device from device-web.
     */
    public function unregister()
    {
        $this->registrar->unregister();
    }

    /**
     * @param string|null $backupPath
     */
    public function backup(string $backupPath = null)
    {
        $this->acquireLock();

        try {
            $this->flasher->backup($backupPath);

            $this->logger->info('IPM0010 Sleeping for 180 seconds as IPMI resets ...');
            $this->sleep->sleep(180);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * @param string|null $backupPath
     */
    public function restore(string $backupPath = null)
    {
        $this->acquireLock();

        try {
            $this->flasher->restore($backupPath);

            $this->logger->info('IPM0011 Sleeping for 180 seconds as IPMI resets ...');
            $this->sleep->sleep(180);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Disable the IPMI LAN interface
     * The BMC has no 'off' feature, so this is not best practice. To really disable unplug it from the network.
     * @return bool success or failure
     */
    public function disable()
    {
        $this->setLanAccess(false);
        $this->setDhcp(false);
        $this->setIpAddress(self::DISABLED_IP);
        $this->setGatewayIpAddress(self::DISABLED_GATEWAY_IP);
        $this->setSubnetMask(self::DISABLED_SUBNET_MASK);
        return true;
    }

    /**
     * Enable/Disable remote access via ipmitool
     * Has no impact on other BMC services exposed on the network
     * Security Note: off is preferred. A user can enable it via the BMC management web site.
     * @param bool $isEnabled
     * @return bool success or failure
     */
    public function setLanAccess($isEnabled)
    {
        $mode = ($isEnabled === true) ? 'on' : 'off';

        $process = $this->processFactory
            ->get([self::IPMITOOL, "lan", "set", "1", "access", $mode])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Returns true if the BMC will respond to remote ipmitool commands
     * @return bool
     */
    public function getLanAccess()
    {
        $isEnabled = false;

        $process = $this->processFactory->get(['ipmitool', 'channel', 'info', "1"]);
        $process->mustRun();
        $output = explode("\n", $process->getOutput());
        foreach ($output as $line) {
            $record = explode(':', $line);
            if (trim($record[0]) === "Access Mode") {
                if (trim($record[1]) === "always available") {
                    return true;
                }
            }
        }

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            throw $e;  // could not reliably determine the state
        }

        return $isEnabled;
    }


    /**
     * Kill all running instances of ipmitool
     * When a Process times out, the subprocess may continue to run.
     * This assumes there should be never more than one ipmitool process normally running.
     * todo: consider something like posix_kill(-1 * $process->getPid(), $signal) to nuke specific process groups
     */
    public function killIpmiTool()
    {

        $process = $this->processFactory->get(['killall', self::IPMITOOL]);

        @$process->run();
    }

    /**
     * Set DHCP as the method to configure IPMI network settings.
     * Security Note: This is not a recommended configuration
     * @param $enabled
     * @return bool success or failure
     */
    public function setDhcp($enabled = true)
    {
        $process = $this->processFactory
            ->get([self::IPMITOOL, "lan", "set", "1", "ipsrc", $enabled ? self::NETWORK_MODE_DHCP : self::NETWORK_MODE_STATIC])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Set a static IP v4 address
     * @param string $ipAddress
     * @return bool success or failure
     */
    public function setIpAddress($ipAddress)
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Invalid IP');
        }

        try {
            $this->retryHandler->executeAllowRetry(function () {
                $process = $this->processFactory
                    ->get([self::IPMITOOL, "lan", "set", "1", "ipsrc", self::NETWORK_MODE_STATIC])
                    ->setTimeout(static::PROCESS_TIMEOUT);
                $process->mustRun();

                return $process;
            });
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('IPM0001 Could not set ip address.', ['exception' => $e]);
            $this->killIpmiTool();
            return false;
        }

        $lanSettings = $this->getLanSettings();

        if ($lanSettings[IpmiService::LAN_SUBNET_MASK] == IpmiService::DISABLED_SUBNET_MASK) {
            // if the subnet mask is too stringent, attempting to set the IP will result in a timeout exception
            $this->setSubnetMask('255.255.255.0');
        }

        try {
            $process = $this->retryHandler->executeAllowRetry(function () use ($ipAddress) {
                $process = $this->processFactory
                    ->get([self::IPMITOOL, "lan", "set", "1", "ipaddr", $ipAddress])
                    ->setTimeout(static::PROCESS_TIMEOUT);
                $process->mustRun();

                return $process;
            });
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('IPM0002 Could not set ip address.', ['exception' => $e]);
            $this->killIpmiTool();
            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->error('IPM0003 Could not set ip address.', ['processOutput' => $process->getErrorOutput()]);
        }

        return $process->isSuccessful();
    }

    /**
     * Configure the subnet mask for IPMI network settings
     *
     * @param string $subnetMask
     * @return bool success or failure
     */
    public function setSubnetMask($subnetMask)
    {
        if (!filter_var($subnetMask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Invalid Subnet Mask');
        }

        $process = $this->processFactory
            ->get([self::IPMITOOL, "lan", "set", "1", "netmask", $subnetMask])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('IPM0004 Could not set subnet mask.', ['exception' => $e]);
            $this->killIpmiTool();
            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->error('IPM0005 Could not set subnet mask.', ['processOutput' => $process->getErrorOutput()]);
        }

        return $process->isSuccessful();
    }

    /**
     * Set a gateway address
     *
     * @param string $gatewayIpAddress
     * @return bool success or failure
     */
    public function setGatewayIpAddress($gatewayIpAddress)
    {
        if (!filter_var($gatewayIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Invalid Gateway IP');
        }

        $process = $this->processFactory
            ->get([self::IPMITOOL, "lan", "set", "1", "defgw", 'ipaddr', $gatewayIpAddress])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('IPM0006 Could not set gateway ip.', ['exception' => $e]);
            $this->killIpmiTool();
            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->error('IPM0007 Could not set gateway ip.', ['processOutput' => $process->getErrorOutput()]);
        }

        return $process->isSuccessful();
    }

    /**
     * Returns true if the IPMI watchdog timer is active and running
     *
     * @return bool True on success, otherwise false
     */
    public function isWatchdogEnabled()
    {
        $process = $this->processFactory
            ->get([self::IPMITOOL, 'mc', 'watchdog', 'get'])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        $output = $process->getOutput();
        if (strpos($output, "Stopped") !== false || strpos($output, "No action") !== false) {
            return false;
        }
        return true;
    }

    /**
     * Disable the IPMI watchdog timer
     *
     * @return bool  true on success, otherwise false
     */
    public function disableWatchdog()
    {
        if (!$this->isWatchdogEnabled()) {
            return true;
        }

        $process = $this->processFactory
            ->get([self::IPMITOOL, 'mc', 'watchdog', 'off'])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        return true;
    }

    /**
     * @param bool $isDhcp
     * @param string|null $staticIpAddress
     * @param string|null $subnetMask
     * @param string|null $gatewayIpAddress
     * @return bool success or failure
     */
    public function setNetworkSettings($isDhcp, $staticIpAddress = null, $subnetMask = null, $gatewayIpAddress = null)
    {
        if ($isDhcp) {
            return $this->setDhcp();
        }

        if ($this->setIpAddress($staticIpAddress) &&
            $this->setSubnetMask($subnetMask) &&
            $this->setGatewayIpAddress($gatewayIpAddress) &&
            $this->setLanAccess(true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Set an IpmiUser's password.
     *
     * @param IpmiUser $ipmiUser
     * @param string $newPassword
     * @return bool success or failure. Will return failure if the process takes longer than
     * IpmiService::PROCESS_TIMEOUT seconds to run.
     */
    public function setPassword($ipmiUser, $newPassword)
    {
        $expectSucceeded = false;

        if (strlen($newPassword) > static::MAX_PASSWORD_LENGTH) {
            throw new \Exception('Password exceeds the maximum length of ' . static::MAX_PASSWORD_LENGTH);
        }

        // note that the password can be passed in from untrusted sources and is not encoded in any way. this is
        // acceptable due to this implementation writing the password out to a secret file and then being provided as
        // input to the expect script.
        if ($this->secretFile->save($newPassword)) {
            $expectSucceeded = $this->executeExpectPasswordScript(
                $ipmiUser->getUserId(),
                $this->secretFile->getFilename()
            );
            $this->secretFile->shred();
        }
        return $expectSucceeded;
    }

    public function setAdminPasswordViaFile(string $pathToPassword)
    {
        $ipmiUsers = $this->getAdminIpmiUsers();
        if (empty($ipmiUsers)) {
            $this->filesystem->shred($pathToPassword);
            throw new \Exception('Failed to find valid IPMI Admin user!');
        }

        if (!$this->filesystem->exists($pathToPassword)) {
            throw new \Exception('Path to IPMI Admin Password does not exist!');
        }
        $passwordLength = $this->filesystem->getSize($pathToPassword) - 1; // subtract EOL
        if ($passwordLength === false || $passwordLength < 2 || $passwordLength > static::MAX_PASSWORD_LENGTH) {
            $this->filesystem->shred($pathToPassword);
            throw new \Exception('Invalid contents at IPMI Admin Password Path');
        }

        $this->acquireLock();
        try {
            foreach ($ipmiUsers as $ipmiUser) {
                $this->executeExpectPasswordScript($ipmiUser->getUserId(), $pathToPassword);
            }
            $this->filesystem->shred($pathToPassword);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Returns IPMI lan information as a key/value array.
     * The LAN_* constants in this class can be used to select a value of interest.
     *
     * Example:  (This is NOT an example of a secure configuration)
     *
     *     "User Lockout Interval": "0",
     *     "Attempt Count Reset Int.": "0",
     *     "Invalid password disable": "no",
     *     "Bad Password Threshold": "0",
     *     "Cipher Suite Priv Max": "Not Available",
     *     "RMCP+ Cipher Suites": "0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15",
     *     "802.1q VLAN Priority": "0",
     *     "802.1q VLAN ID": "Disabled",
     *     "Backup Gateway MAC": "00:00:00:00:00:00",
     *     "Backup Gateway IP": "0.0.0.0",
     *     "Default Gateway MAC": "00:00:00:00:00:00",
     *     "IP Address Source": "DHCP Address",
     *     "Auth Type OEM": "",
     *     "Auth Type Admin": "MD2 MD5 PASSWORD OEM",
     *     "Auth Type Operator": "MD2 MD5 PASSWORD OEM",
     *     "Auth Type User": "MD2 MD5 PASSWORD OEM",
     *     "Auth Type Callback": "MD2 MD5 PASSWORD OEM",
     *     "Auth Type Support": "NONE MD2 MD5 PASSWORD OEM",
     *     "Set in Progress": "Set Complete",
     *     "IP Address": "10.70.70.123",
     *     "Subnet Mask": "255.255.252.0",
     *     "MAC Address": "1c:1b:0d:0c:12:34",
     *     "SNMP Community String": "public",
     *     "IP Header": "TTL=0x40 Flags=0x40 Precedence=0x00 TOS=0x10",
     *     "BMC ARP Control": "ARP Responses Enabled,Gratuitous ARP Disabled",
     *     "Gratituous ARP Intrvl": "10.0 seconds",
     *     "Default Gateway IP": "10.70.12.34"
     *
     * @return array|bool
     */
    public function getLanSettings()
    {
        $lanSettings = array();

        // parse the output of ipmitool and put it in a key/value array if successful. Keys are based on output labels.
        // A slight transform is made so 'Auth Type (Callback|User|Operator|Admin|OEM)' can be used to select a particular auth type record.
        $process = $this->processFactory
            ->getFromShellCommandLine('ipmitool lan print 1 | sed \'s/ *:/|/;s/| */|/g;s/^Auth Type Enable//\' | sed -r \'s/^\|(Callback|User|Operator|Admin|OEM) *:/Auth Type \1\|/;s/\| */|/;s/, /,/g\'')
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        if (!$process->isSuccessful()) {
            return false;
        }

        $commandOutput = explode("\n", $process->getOutput());
        foreach ($commandOutput as $line) {
            $keyValuePair = explode('|', $line);
            if (!empty($keyValuePair[0])) {
                $lanSettings[$keyValuePair[0]] = trim($keyValuePair[1]);
            }
        }
        return $lanSettings;
    }

    /**
     * Return the list of IPMI user records keyed by ID.
     * If $allRecords is true, the entire IPMI user table will be returned
     * including 'no access' user ids and unnamed users.
     *
     * @param bool $allRecords defaults false
     * @return array|bool
     */
    public function getUsers($allRecords = false)
    {
        $process = $this->processFactory
            ->get([
                self::IPMITOOL,
                '-c',// comma delimited output, only works with a handful of ipmitool commands
                'user',
                'list',
                '1'
            ])
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            $this->killIpmiTool();
            return false;
        }

        if (!$process->isSuccessful()) {
            return false;
        }

        $commandOutput = explode("\n", $process->getOutput());
        $users = array();
        foreach ($commandOutput as $line) {
            $userRecord = explode(',', $line);
            if (!empty($userRecord[0])) {
                $user = array();
                $userID = intval($userRecord[0]);
                // A more verbose dump can be viewed via 'ipmitool channel getaccess 1'
                $user[IpmiService::USER_NAME] = $userRecord[1];
                $user[IpmiService::USER_CAN_CALLIN] = boolval($userRecord[2]);
                $user[IpmiService::USER_CAN_LINK_AUTH] = boolval($userRecord[3]);
                $user[IpmiService::USER_CAN_IPMI_MSG] = boolval($userRecord[4]);
                $user[IpmiService::USER_CHANNEL_PRIV_LIMIT] = $userRecord[5];
                if ($allRecords || ($user[IpmiService::USER_NAME] !== '' && $user[IpmiService::USER_CHANNEL_PRIV_LIMIT] !== 'NO ACCESS')) {
                    $users[$userID] = $user;
                }
            }
        }
        return $users;
    }

    /**
     * @return IpmiUser[]
     */
    private function getAdminIpmiUsers(): array
    {
        $adminUsers = [];
        $users = $this->getUsers() ?: [];
        foreach ($users as $userId => $user) {
            if (strtolower($user[IpmiService::USER_NAME]) === IpmiService::ADMIN_USER_NAME) {
                $adminUsers[] = new IpmiUser($user[IpmiService::USER_NAME], $userId);
            }
        }
        return $adminUsers;
    }

    /**
     * Consolidates network/user information and returns a data structure used to render the IPMI section in the UI
     *
     * @return IpmiSettings
     */
    public function getCurrentSettings()
    {
        $lanSettings = $this->getLanSettings();
        $users = $this->getUsers();

        $isStatic = $lanSettings[IpmiService::LAN_IP_ADDRESS_SOURCE] !== IpmiService::DHCP_ADDRESS;
        $ip = $lanSettings[IpmiService::LAN_IP_ADDRESS];
        $isEnabled = $ip !== IpmiService::DISABLED_IP;  // we use 1.1.1.1/255.255.255.254 as an unroutable/disabled configuration
        $subnetMask = $lanSettings[IpmiService::LAN_SUBNET_MASK];
        $gatewayIP = $lanSettings[IpmiService::LAN_DEFAULT_GATEWAY_IP];

        $activeUsers = array();
        foreach ($users as $userID => $user) {
            if ($userID === 1) {  // IPMI reserves user ID 1 as the null user whose name cannot be changed
                continue;         // It is filtered out since it is not rendered in the UI
            }
            $ipmiUser = new IpmiUser($user[IpmiService::USER_NAME], $userID);
            array_push($activeUsers, $ipmiUser);
        }

        return new IpmiSettings(
            $isEnabled,
            $isStatic,
            $ip,
            $subnetMask,
            $gatewayIP,
            $activeUsers
        );
    }

    private function executeExpectPasswordScript(int $ipmiUserId, string $secretFilePath): bool
    {
        $expectSucceeded = $this->expect->run(
            $this->getPasswordScriptPath(),
            $ipmiUserId,
            $secretFilePath
        );

        if (!$expectSucceeded) {
            // When the timeout is hit, the parent sh process spawned by Symfony is terminated, but the underlying
            // ipmitool process is left running, and we need to kill it with fire or it will hang around for a while.
            $doPkill = $this->processFactory->get(['pkill', basename(static::IPMITOOL)]);
            $doPkill->run();
        }
        return $expectSucceeded;
    }

    /**
     * @return string
     */
    private function getPasswordScriptPath(): string
    {
        return __DIR__ .  '/../../app/Resources/Ipmi/ipmitool-chpasswd.exp';
    }

    /**
     * Acquire an exclusive lock for critical IPMI operations (does not block).
     */
    private function acquireLock()
    {
        if (!$this->lock->exclusive(false)) {
            throw new \Exception("Could not acquire IPMI lock: " . self::LOCK_PATH);
        }
    }
}
