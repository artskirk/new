<?php

namespace Datto\Restore\Serializer;

use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\AssetService;
use Datto\Asset\Serializer\Serializer;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Connection\Service\ConnectionService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Datto\Restore\AgentVirtualizationRestore;
use Datto\Restore\ExportRestore;
use Datto\Restore\Restore;
use Datto\Restore\RestoreType;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\Util\DateTimeZoneService;
use Datto\Virtualization\RemoteHypervisorStorageFactory;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyUIRestoresSerializer implements Serializer
{
    private AssetService $assetService;
    private ProcessFactory $processFactory;
    private ConnectionService $connectionService;
    private AgentSnapshotService $agentSnapshotService;
    private RemoteHypervisorStorageFactory $remoteHypervisorStorageFactory;
    private DeviceLoggerInterface $logger;
    private DateTimeZoneService $dateTimeZoneService;
    private VirtualDisksFactory $virtualDisksFactory;
    private VirtualMachineService $virtualMachineService;

    public function __construct(
        AssetService $assetService = null,
        ProcessFactory $processFactory = null,
        ConnectionService $connectionService = null,
        AgentSnapshotService $agentSnapshotService = null,
        RemoteHypervisorStorageFactory $remoteHypervisorStorageFactory = null,
        DeviceLoggerInterface $logger = null,
        DateTimeZoneService $dateTimeZoneService = null,
        VirtualDisksFactory $virtualDisksFactory = null,
        VirtualMachineService $virtualMachineService = null
    ) {
        $this->assetService = $assetService ?: new AssetService();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->connectionService = $connectionService ?: new ConnectionService();
        $this->agentSnapshotService = $agentSnapshotService ?: new AgentSnapshotService();
        $this->remoteHypervisorStorageFactory = $remoteHypervisorStorageFactory ?: new RemoteHypervisorStorageFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->virtualDisksFactory = $virtualDisksFactory ?? new VirtualDisksFactory($this->agentSnapshotService);
        $this->virtualMachineService = $virtualMachineService ?? new VirtualMachineService();
    }

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param Restore[] $restores
     * @return array $serializedArray
     */
    public function serialize($restores)
    {
        $serializedArray = array();

        /** @var Restore $restore */
        foreach ($restores as $restore) {
            $serializedArray[$restore->getUiKey()] = array(
                'agent' => $restore->getAssetKey(),
                'point' => $restore->getPoint(),
                'activationTime' => $restore->getActivationTime(),
                'restore' => $restore->getSuffix(),
                'options' => $restore->getOptions(),
                'html' => $restore->getHtml()
            );
        }

        return $serializedArray;
    }

    /**
     * Create an object from the given array.
     *
     * @param array $restores
     * @return Restore[] $array
     */
    public function unserialize($restores)
    {
        $array = array();

        foreach ($restores as $restore) {
            $assetKey = $restore['agent'];

            $point = $restore['point'];
            $suffix = $restore['restore'];
            $activationTime = $restore['activationTime'];
            $options = isset($restore['options']) ? $restore['options'] : array();
            $html = isset($restore['html']) ? $restore['html'] : '';

            if ($suffix === RestoreType::ACTIVE_VIRT || $suffix === RestoreType::RESCUE) {
                $restore = new AgentVirtualizationRestore(
                    $assetKey,
                    $point,
                    $suffix,
                    $activationTime,
                    $options,
                    $html,
                    $this->assetService,
                    $this->processFactory,
                    $this->connectionService,
                    $this->agentSnapshotService,
                    $this->remoteHypervisorStorageFactory,
                    $this->logger,
                    $this->dateTimeZoneService,
                    $this->virtualDisksFactory,
                    $this->virtualMachineService
                );
            } elseif ($suffix === RestoreType::EXPORT) {
                $restore = new ExportRestore(
                    $assetKey,
                    $point,
                    $suffix,
                    $activationTime,
                    $options,
                    $html,
                    $this->assetService,
                    $this->processFactory,
                    $this->dateTimeZoneService
                );
            } else {
                // todo: replace with call to RestoreFactory
                $restore = new Restore(
                    $assetKey,
                    $point,
                    $suffix,
                    $activationTime,
                    $options,
                    $html,
                    $this->assetService,
                    $this->processFactory,
                    $this->dateTimeZoneService
                );
            }

            $array[$assetKey.$point.$suffix] = $restore;
        }

        return $array;
    }
}
