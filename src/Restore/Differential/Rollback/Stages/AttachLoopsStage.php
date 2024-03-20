<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Asset\Agent\MountLoopHelper;
use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Log\DeviceLoggerInterface;

/**
 * Attach .datto files to loop devices.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class AttachLoopsStage extends AbstractStage
{
    /** @var MountLoopHelper */
    private $mountLoopHelper;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param MountLoopHelper $mountLoopHelper
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        MountLoopHelper $mountLoopHelper
    ) {
        parent::__construct($context, $logger);

        $this->mountLoopHelper = $mountLoopHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $cloneDir = $this->context->getCloneSpec()->getTargetMountpoint();
        $encrypted = !empty($this->context->getPassphrase());

        $loopMap = $this->mountLoopHelper->attachLoopDevices($assetKey, $cloneDir, $encrypted);

        // $loopMap is <driveUuid> => <loopDevice>, we need <index> => <loopPartition>
        $lunPaths = [];
        foreach ($loopMap as $uuid => $loopDevice) {
            $lunPaths[] = $loopDevice . 'p1';
        }

        $this->context->setLunPaths($lunPaths);
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $cloneDir = $this->context->getCloneSpec()->getTargetMountpoint();

        $this->logger->info('DFR0011 Detaching loop devices from clone directory', ['cloneDir' => $cloneDir]);

        $this->mountLoopHelper->detachLoopDevices($cloneDir);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // nothing
    }
}
