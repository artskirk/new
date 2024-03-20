<?php

namespace Datto\Restore\Export\Network;

use Datto\Asset\Agent\AgentService;
use Datto\Filesystem\StitchfsMountFactory;
use Datto\Filesystem\TransparentMount;
use Datto\Filesystem\TransparentMountFactory;
use Datto\ImageExport\BootType;
use Datto\ImageExport\Filesystem\StitchfsMount;
use Datto\ImageExport\ImageType;
use Datto\ImageExport\Status;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\Export\Context;
use Datto\Restore\Export\ImageExporter;
use Datto\System\MountManager;
use Psr\Log\LoggerAwareInterface;

/**
 * Base class for any shared logic between network exporters.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class NetworkExporter implements ImageExporter, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private ?BootType $bootType;
    private NetworkExportTransactionFactory $transactionFactory;
    private ImageType $imageType;
    private MountManager $mountManager;
    private StitchfsMountFactory $stitchfsMountFactory;
    private TransparentMountFactory $transparentMountFactory;

    public function __construct(
        AgentService $agentService,
        NetworkExportTransactionFactory $transactionFactory,
        MountManager $mountManager,
        StitchfsMountFactory $stitchfsMountFactory,
        TransparentMountFactory $transparentMountFactory
    ) {
        $this->agentService = $agentService;
        $this->transactionFactory = $transactionFactory;
        $this->mountManager = $mountManager;
        $this->stitchfsMountFactory = $stitchfsMountFactory;
        $this->transparentMountFactory = $transparentMountFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function export(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info(
            'EXP1000 Exporting image',
            ['type' => $this->imageType->value(), 'snapshot' => $snapshotEpoch]
        );

        $context = $this->getContext($agentName, $snapshotEpoch);
        $transaction = $this->transactionFactory->createExportTransaction($context);
        $transaction->commit();

        $this->logger->info('EXP1007 Image exported successfully.');
    }

    /**
     * {@inheritdoc}
     */
    public function repair(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info(
            'EXP3000 Repairing export of image type',
            ['type' => $this->imageType->value(), 'snapshot' => $snapshotEpoch]
        );

        $context = $this->getContext($agentName, $snapshotEpoch);
        $transaction = $this->transactionFactory->createRepairTransaction($context);

        $transaction->commit();

        $this->logger->info('EXP3005 Image export repaired successfully.');
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info(
            'EXP2000 Removing export of type',
            ['type' => $this->imageType->value(), 'snapshot' => $snapshotEpoch]
        );

        $context = $this->getContext($agentName, $snapshotEpoch);
        $transaction = $this->transactionFactory->createRemoveTransaction($context);
        $transaction->commit();

        $this->logger->info('EXP2005 Image export removed successfully.');
    }

    /**
     * {@inheritdoc}
     */
    public function isExported(string $agentName, int $snapshotEpoch): bool
    {
        $this->logger->setAssetContext($agentName);

        return $this->mountManager->isMounted($this->getContext($agentName, $snapshotEpoch)->getCloneMountPoint());
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(string $agentName, int $snapshotEpoch): Status
    {
        $this->logger->setAssetContext($agentName);

        return $this->getContext($agentName, $snapshotEpoch)->getStatus();
    }

    public function setBootType(BootType $bootType = null)
    {
        $this->bootType = $bootType;
    }

    public function setImageType(ImageType $imageType)
    {
        $this->imageType = $imageType;
    }

    /**
     * Create a context object for use in the export stages.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @return Context
     */
    private function getContext($agentName, $snapshotEpoch)
    {
        $agent = $this->agentService->get($agentName);

        $fuseMounter = $this->imageType === ImageType::VMDK_LINKED()
            ? $this->transparentMountFactory->create()
            : $this->stitchfsMountFactory->create();

        return new Context(
            $agent,
            $snapshotEpoch,
            $this->imageType,
            $fuseMounter,
            false,
            $this->bootType
        );
    }
}
