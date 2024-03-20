<?php

namespace Datto\Restore\Export\Network;

use Datto\AppKernel;
use Datto\Asset\Agent\AgentService;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\ImageExport\Status;
use Datto\Log\LoggerFactory;
use Datto\Restore\Export\ImageExporter;
use Datto\Restore\Export\Stages\ImageConversionHelper;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * This class is responsible for constructing a NetworkExporter and exporting an image of a specific type.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class NetworkExportService
{
    /** @var ImageConversionHelper */
    private $helper;

    /** @var AgentService */
    private $agentService;

    public function __construct(
        ImageConversionHelper $helper = null,
        AgentService $agentService = null
    ) {
        $this->helper = $helper ?: new ImageConversionHelper();
        $this->agentService = $agentService ?: new AgentService();
    }

    /**
     * Export an image of a specific type.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param ImageType $type
     * @param BootType|null $bootType
     * @param ImageExporter|null $exporter
     * @param DeviceLoggerInterface|null $logger
     */
    public function export(
        $agentName,
        $snapshotEpoch,
        ImageType $type,
        BootType $bootType = null,
        ImageExporter $exporter = null,
        DeviceLoggerInterface $logger = null
    ) {
        $logger = $logger ?: LoggerFactory::getAssetLogger($agentName);
        $exporter = $exporter ?: $this->createExporter($type, $bootType);

        $this->validateImageType($agentName, $logger, $type);

        // remove any previous exports
        $this->removeAll($agentName, $snapshotEpoch, $exporter);

        $exporter->export($agentName, $snapshotEpoch);
    }

    /**
     * Remove an export of a specific image type.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param ImageType $type
     * @param ImageExporter|null $exporter
     */
    public function remove(
        $agentName,
        $snapshotEpoch,
        ImageType $type,
        ImageExporter $exporter = null
    ) {
        $exporter = $exporter ?: $this->createExporter($type);
        if ($exporter->isExported($agentName, $snapshotEpoch)) {
            $exporter->remove($agentName, $snapshotEpoch);
        }
    }

    /**
     * Repair an image export (e.g. after reboot fix a network share)
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param ImageType $type
     * @param ImageExporter|null $exporter
     * @param DeviceLoggerInterface|null $logger
     * @throws Exception
     */
    public function repair(
        string $agentName,
        int $snapshotEpoch,
        ImageType $type,
        ImageExporter $exporter = null,
        DeviceLoggerInterface $logger = null
    ) {
        $logger = $logger ?: LoggerFactory::getAssetLogger($agentName);
        $exporter = $exporter ?: $this->createExporter($type);

        $this->validateImageType($agentName, $logger, $type);

        $exporter->repair($agentName, $snapshotEpoch);
    }

    public function removeAll(
        $agentName,
        $snapshotEpoch,
        $exporter = null
    ) {
        $agent = $this->agentService->get($agentName);
        foreach ($this->helper->getSupportedFormats($agent) as $type) {
            $this->remove($agentName, $snapshotEpoch, $type, $exporter);
        }
    }

    /**
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param ImageType $type
     * @param ImageExporter|null $exporter
     * @return Status
     */
    public function getStatus(
        $agentName,
        $snapshotEpoch,
        ImageType $type,
        ImageExporter $exporter = null
    ) {
        $exporter = $exporter ?: $this->createExporter($type);

        return $exporter->getStatus($agentName, $snapshotEpoch);
    }

    /**
     * Check to see if an image-type is supported.
     *
     * @param string $agentName
     * @param ImageType $type
     * @return bool
     */
    public function isSupported(string $agentName, ImageType $type)
    {
        $agent = $this->agentService->get($agentName);
        return in_array($type, $this->helper->getSupportedFormats($agent));
    }

    private function createExporter(ImageType $imageType, BootType $bootType = null): NetworkExporter
    {
        $container = AppKernel::getBootedInstance()->getContainer();
        $exporter = $container->get(NetworkExporter::class);

        $exporter->setImageType($imageType);
        $exporter->setBootType($bootType);

        return $exporter;
    }

    /**
     * Validate an image-type to ensure it's supported. An exception will be thrown
     * if the image-type is not valid (eg. not supported).
     *
     * @param string $agentName
     * @param DeviceLoggerInterface $logger
     * @param ImageType $type
     */
    private function validateImageType(string $agentName, DeviceLoggerInterface $logger, ImageType $type)
    {
        if (!$this->isSupported($agentName, $type)) {
            $logger->warning('EXP0003 This export is not supported on this device (some exports are only supported on 16.04 and above)', ['export' => $type->value()]);
            throw new Exception(sprintf('%s exports are not supported on this device', $type->value()));
        }
    }
}
