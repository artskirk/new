<?php

namespace Datto\Service\Security;

use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Datto\Utility\Network\IpHelper;
use Datto\Common\Resource\Filesystem;

/**
 * Service to handle Suricata on boot if applicable. Suricata is the IDS used for devices hosted in Azure.
 *
 * @author Huan-Yu Yih <hyih@datto.com>
 */
class Suricata implements LoggerAwareInterface
{
    # Suricata configuration file.
    public const SURICATA_YAML_CONFIG_PATH = '/etc/suricata/suricata.yaml';
    # List of interfaces from which to retrieve IPs for configuration
    public const SURICATA_INTERFACE_LIST = ['eth0', 'br0'];

    use LoggerAwareTrait;

    /** @var FeatureService */
    private $featureService;

    /** @var Filesystem */
    private $filesystem;

    /** @var IpHelper */
    private $ipHelper;

    public function __construct(
        FeatureService $featureService,
        Filesystem $filesystem,
        IpHelper $ipHelper
    ) {
        $this->featureService = $featureService;
        $this->filesystem = $filesystem;
        $this->ipHelper = $ipHelper;
    }

    private function getAllIps(): array
    {
        try {
            // Get IP address objects, convert to array of strings
            $addressList = [];
            foreach (self::SURICATA_INTERFACE_LIST as $interfaceName) {
                $interface = $this->ipHelper->getInterface($interfaceName);
                if ($interface !== null) {
                    foreach ($interface->getAddresses() as $ip) {
                        array_push($addressList, $ip->getCidr());
                    }
                } else {
                    $this->logger->error("SUR0007 Could not retrieve interface", ['interface' => $interfaceName]);
                }
            }

            return $addressList;
        } catch (\Exception $ex) {
            $this->logger->error(
                "SUR0009 Exception while getting ip information",
                ['message' => $ex->getMessage()]
            );
        }
        return [];
    }

    private function formatIpList(array $ips) : string
    {
        $list = implode(',', $ips);
        return "[$list]";
    }

    private function updateSuricataYaml(string $yamlPath, array $ipList): bool
    {
        $updateCount = 0;
        $ipValue = "";
        try {
            $suricataConfigText = $this->filesystem->fileGetContents($yamlPath);
            if ($suricataConfigText === false) {
                $this->logger->error('SUR0010 Could not retrieve contents of config file', [
                    'configfile' => $yamlPath
                ]);
            } else {
                $ipValue = $this->formatIpList($ipList);
                $suricataConfigUpdated = preg_replace(
                    "/HOME_NET:.*/",
                    "HOME_NET: \"$ipValue\"",
                    $suricataConfigText,
                    -1,
                    $updateCount
                );
                $saveResult = $this->filesystem->filePutContents($yamlPath, $suricataConfigUpdated);
                $logContext = [
                    'configfile' => $yamlPath,
                    'homenetip' => $ipValue,
                    'updatecount' => $updateCount
                ];
                if ($saveResult === false) {
                    $this->logger->error('SUR0011 Failed to write config file', $logContext);
                    return false;
                } else {
                    $this->logger->info('SUR0005 Successfully read, updated and wrote config file', $logContext);
                }
                return true;
            }
        } catch (\Exception $ex) {
            $this->logger->error('SUR0006 Exception while updating YAML file', [
                'configfile' => $yamlPath,
                'homenetip' => $ipValue,
                'updatecount' => $updateCount,
                'message' => $ex->getMessage()
            ]);
        }
        return false;
    }

    private function updateSuricataHomeNet(): bool
    {
        try {
            $ipList = $this->getAllIps();
            if (count($ipList) > 0) {
                return $this->updateSuricataYaml(self::SURICATA_YAML_CONFIG_PATH, $ipList);
            } else {
                $this->logger->error('SUR0003 Did not retrieve any ips');
            }
        } catch (\Throwable $e) {
            $this->logger->error('SUR0004 Exception while updating HOME_NET in suricata config', [
                'message' => $e->getMessage()
            ]);
        }
        return false;
    }

    public function configure(): void
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_SURICATA);

        $this->logger->info('SUR0001 Configuring Suricata IDS service');
        if ($this->updateSuricataHomeNet()) {
            $this->logger->info('SUR0002 Successfully configured Suricata IDS service');
        } else {
            $this->logger->error('SUR0012 Suricata IDS configuration was unsuccessful');
        };
    }
}
