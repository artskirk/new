<?php

namespace Datto\Config\RepairTask;

use Datto\Config\AgentConfigFactory;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLoggerInterface;

/**
 * Remove obsolete agent key files
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class RemoveObsoleteAgentKeys implements ConfigRepairTaskInterface
{
    const KEYS = [
        'zfsEncryption',
        'shareCompatibility',
        'compatibilityShareName',
        'warnTimestamp',
        'failTimestamp',
        'dnsTimestamp',
        'needsCloudUpdate',
        'needsOffsite',
        'backend',
        'dtcAllowRetention',
        'needsReboot',
        'includeMeta'
    ];

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /**
     * @param DeviceLoggerInterface $logger
     * @param AgentConfigFactory $agentConfigFactory
     */
    public function __construct(DeviceLoggerInterface $logger, AgentConfigFactory $agentConfigFactory)
    {
        $this->logger = $logger;
        $this->agentConfigFactory = $agentConfigFactory;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $changesOccurred = false;
        $assetKeyNames = $this->agentConfigFactory->getAllKeyNames();

        foreach ($assetKeyNames as $assetKey) {
            $agentConfig = $this->agentConfigFactory->create($assetKey);

            foreach (self::KEYS as $key) {
                if ($agentConfig->has($key)) {
                    $this->logger->warning(
                        'CFG0019 clearing obsolete asset key',
                        ['keyFilePath' => $agentConfig->getConfigFilePath($key)]
                    );
                    $agentConfig->clear($key);
                    $changesOccurred = true;
                }
            }
        }

        return $changesOccurred;
    }
}
