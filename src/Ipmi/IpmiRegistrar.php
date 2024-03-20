<?php

namespace Datto\Ipmi;

use Datto\Cloud\JsonRpcClient;
use Datto\Security\PasswordGenerator;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles registering the motherboard's IPMI with device-web.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiRegistrar
{
    const SECRET_KEY_LENGTH = 32;

    /** @var JsonRpcClient */
    private $client;

    /** @var IpmiTool */
    private $ipmiTool;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param JsonRpcClient $client
     * @param IpmiTool $ipmiTool
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        JsonRpcClient $client,
        IpmiTool $ipmiTool,
        DeviceLoggerInterface $logger
    ) {
        $this->client = $client;
        $this->ipmiTool = $ipmiTool;
        $this->logger = $logger;
    }

    /**
     * Check if already registered.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return (bool)$this->client->queryWithId('v1/device/ipmiRegistration/exists', []);
    }

    /**
     * Register the IPMI of this device with device-web. Once registered, the BMC must be flashed within
     * an allotted time period in order for it to checkin and grab the temporarily-available secret key.
     */
    public function register()
    {
        try {
            $this->logger->info('IRR0001 Registering IPMI with device-web ...');

            $ipmi = FlashableIpmi::create();

            $ipmiMac = $this->getIpmiMac();
            $ipmiSecretKey = $this->generateSecretKey();
            $ipmiHomepage = $ipmi->getHomepage();
            $ipmiPort = $ipmi->getPort();

            $this->client->queryWithId('v1/device/ipmiRegistration/create', [
                'ipmiMac' => $ipmiMac,
                'ipmiSecretKey' => $ipmiSecretKey,
                'ipmiHomepage' => $ipmiHomepage,
                'ipmiPort' => $ipmiPort
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Unable to register IPMI: ' . $e->getMessage());
        }
    }

    /**
     * Unregister the IPMI of this device from device-web.
     */
    public function unregister()
    {
        try {
            $this->logger->info('IRR0002 Unregistering IPMI from device-web ...');

            $this->client->queryWithId('v1/device/ipmiRegistration/delete');
        } catch (\Exception $e) {
            throw new \Exception('Unable to unregister IPMI: ' . $e->getMessage());
        }
    }

    /**
     * @return string
     */
    private function getIpmiMac(): string
    {
        return $this->ipmiTool->getLan()->getNormalizedMacAddress();
    }

    /**
     * @return string
     */
    private function generateSecretKey(): string
    {
        return PasswordGenerator::generate(self::SECRET_KEY_LENGTH);
    }
}
