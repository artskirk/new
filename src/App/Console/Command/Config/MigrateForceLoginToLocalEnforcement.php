<?php

namespace Datto\App\Console\Command\Config;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Disable "force login" in the portal for every device. It is now enforced locally.
 *
 * The "force login" setting used to be saved in the device-web database with the /datto/config/remoteWebForceLogin
 * key as a local cache so users could see what the setting was. The setForceLogin endpoint called below controls
 * whether the portal sends us remote login parameters.
 *     forceLogin = true    means don't send remote login parameters
 *     forceLogin = false   means send remote login parameters
 *
 * Now we save the force login setting locally on the device and tell the portal to _always_ send the remote login
 * parameters.
 * This allows us to use the cryptographically signed remote login parameters to verify whether the user logging in
 * has valid portal credentials. Since the portal has 2 factor authentication, we can trust those users more than
 * normal local users. We use that trust to elevate the permissions of the user that logs in when "force login"
 * is enabled. This allows them to delete data.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class MigrateForceLoginToLocalEnforcement extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'config:login:force:migrate';

    /** @var JsonRpcClient */
    private $jsonRpcClient;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(JsonRpcClient $jsonRpcClient, DeviceConfig $deviceConfig)
    {
        parent::__construct();
        $this->jsonRpcClient = $jsonRpcClient;
        $this->deviceConfig = $deviceConfig;
    }

    protected function configure()
    {
        $this->setDescription('Disable force login to the device over remote web');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->deviceConfig->has(DeviceConfig::KEY_REMOTE_WEB_FORCE_LOGIN)) {
            $this->logger->info('FLO0001 Migrating "force login" to local enforcement. "Force login" will be set to false in the db.');
            $this->jsonRpcClient->queryWithId('v1/device/remoteWeb/setForceLogin', ['forceLogin' => false]);
        }
        return 0;
    }
}
