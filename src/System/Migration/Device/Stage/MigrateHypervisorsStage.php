<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Service\ConnectionService;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Datto\Feature\FeatureService;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Migrate hypervisor configurations from source device to target
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class MigrateHypervisorsStage extends AbstractMigrationStage
{
    const DEVICE_TARGET = 'device';

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /** @var ConnectionService */
    private $connectionService;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var HvConnectionService */
    private $hvConnectionService;

    /** @var FeatureService */
    private $featureService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param DeviceApiClientService $deviceClient
     * @param AgentConfigFactory $agentConfigFactory
     * @param ConnectionService $connectionService
     * @param EsxConnectionService $esxConnectionService
     * @param HvConnectionService $hvConnectionService
     * @param FeatureService $featureService
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        DeviceApiClientService $deviceClient,
        AgentConfigFactory $agentConfigFactory,
        ConnectionService $connectionService,
        EsxConnectionService $esxConnectionService,
        HvConnectionService $hvConnectionService,
        FeatureService $featureService
    ) {
        parent::__construct($context);

        $this->logger = $logger;
        $this->deviceClient = $deviceClient;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->connectionService = $connectionService;
        $this->esxConnectionService = $esxConnectionService;
        $this->hvConnectionService = $hvConnectionService;
        $this->featureService = $featureService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $hypervisorConnections = $this->fetchHypervisorsToMigrate();
        $this->migrateHypervisors($hypervisorConnections);
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }

    /**
     * Fetch all settings from the source device. We do this so we're more resilient to failures in case we lose the
     * connection. We won't have half migrated settings.
     *
     * @return array An array of hypervisor connection array objects to migrate
     */
    private function fetchHypervisorsToMigrate() : array
    {
        $hypervisorConnections = [];
        $connectionsToMigrate = [];

        // API endpoint returns an array, not bool...
        $remoteConnectionsSupportInfo = $this->deviceClient->call(
            'v1/device/feature/isSupported',
            ['feature' => FeatureService::FEATURE_HYPERVISOR_CONNECTIONS]
        );

        // do not migrate connections, if neither source or target support it
        $hvMigrationSupported =
            $this->featureService->isSupported(FeatureService::FEATURE_HYPERVISOR_CONNECTIONS) &&
            $remoteConnectionsSupportInfo['supported'];

        if ($hvMigrationSupported) {
            // Get the list of all hypervisor connections from the source system
            $hypervisorConnections = $this->deviceClient->call(
                'v1/device/connections/getAll',
                []
            );
        } else {
            $this->logger->warning('MHS0001 Attempted to migrate hypervisors to a system that does not support it.  Skipping.');
        }

        // Get the list of hypervisor connections that need to be migrated for the targets that are being migrated
        foreach ($this->context->getTargets() as $target) {
            if ($target !== MigrateHypervisorsStage::DEVICE_TARGET) {
                $agentConfig = $this->agentConfigFactory->create($target);
                $targetEsxConnectionName = null;
                $targetHvConnectionName = null;
                // Check for key file referencing a VMWare connection
                $esxInfo = $agentConfig->getRaw(EsxInfo::KEY_NAME);
                if ($esxInfo) {
                    $esxInfo = unserialize($esxInfo, ['allowed_classes' => false]);
                    $targetEsxConnectionName = $esxInfo['connectionName'];
                }
                // Check for key file referencing a HyperV connection
                $hvInfo = $agentConfig->getRaw('hvInfo');
                if ($hvInfo) {
                    $hvInfo = unserialize($hvInfo, ['allowed_classes' => false]);
                    $targetHvConnectionName = $hvInfo['connectionName'];
                }
                foreach ($hypervisorConnections as $hypervisor) {
                    $hypervisorName = $hypervisor['name'];
                    if ($hypervisorName === $targetEsxConnectionName ||
                        $hypervisorName === $targetHvConnectionName) {
                        $connectionsToMigrate[] = $hypervisor;
                    }
                }
            }
        }

        return $connectionsToMigrate;
    }

    /**
     * Perform some final checks on the hypervisors that are candidates to be migrated, and actually save them locally.
     *
     * @param array $hypervisorConnections
     */
    private function migrateHypervisors(array $hypervisorConnections)
    {
        foreach ($hypervisorConnections as $hypervisor) {
            $existingHypervisor = $this->connectionService->get($hypervisor['name']);
            if ($existingHypervisor) {
                if ($existingHypervisor->getType()->value() !== $hypervisor['type']) {
                    $this->logger->warning(
                        'MHS0002 Attempted to migrate hypervisor with name that matched existing hypervisor with different type.  Skipping migration for this hypervisor.',
                        ['hypervisorName' => $hypervisor['name']]
                    );
                }
            } elseif ($hypervisor['type'] === ConnectionType::LIBVIRT_ESX) {
                $this->esxConnectionService->copy($hypervisor);
            } elseif ($hypervisor['type'] === ConnectionType::LIBVIRT_HV) {
                $this->hvConnectionService->copy($hypervisor);
            }
        }
    }
}
