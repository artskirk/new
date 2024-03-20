<?php

namespace Datto\Restore\Export\Stages;

use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Resource\DateTimeService;

/**
 * Base class for stages that manipulate the UI restores.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
abstract class AbstractRestoreUpdateStage extends AbstractStage
{
    /** @var RestoreService */
    protected $restoreService;

    /** @var DateTimeService */
    protected $timeService;

    public function __construct(
        RestoreService $restoreService,
        DateTimeService $timeService
    ) {
        $this->restoreService = $restoreService;
        $this->timeService = $timeService;
    }

    /**
     * Create a new UI restore
     */
    protected function createRestore()
    {
        $agentName = $this->context->getAgent()->getKeyName();
        $imageType = $this->context->getImageType()->value();
        $bootType = $this->context->getBootType()->value();
        $snapshotEpoch = $this->context->getSnapshot();

        $restore = $this->restoreService->create(
            $agentName,
            $snapshotEpoch,
            RestoreType::EXPORT,
            $this->timeService->getTime(),
            [
                'complete' => false,
                'failed' => false,
                'image-type' => $imageType,
                'boot-type' => $bootType,
                'network-export' => $this->context->isNetworkExport(),
                'nfs-path' => $this->context->getMountPoint(),
                'share-name' => strtoupper(sprintf(
                    '%s-%s-%s',
                    $this->context->getAgent()->getHostname(),
                    $snapshotEpoch,
                    $imageType
                ))
            ]
        );

        // not sure why this is implemented this way... but you have to call getAll before saving
        $this->restoreService->getAll();
        $this->restoreService->add($restore);
        $this->restoreService->save();
    }

    /**
     * Remove the UI restore
     */
    protected function removeRestore()
    {
        $agentName = $this->context->getAgent()->getKeyName();
        $snapshotEpoch = $this->context->getSnapshot();

        $restore = $this->restoreService->find($agentName, $snapshotEpoch, RestoreType::EXPORT);

        if ($restore !== null) {
            $this->restoreService->delete($restore);
            $this->restoreService->save();
        }
    }

    /**
     * Update the UI restore, optionally changing some of the options.
     *
     * @param array $optionChanges Associative array of options to change, unspecified options will remain the same
     */
    protected function updateRestore(array $optionChanges)
    {
        $agentName = $this->context->getAgent()->getKeyName();
        $snapshotEpoch = $this->context->getSnapshot();

        $restore = $this->restoreService->find($agentName, $snapshotEpoch, RestoreType::EXPORT);
        if ($restore) {
            $options = $restore->getOptions();
            foreach ($optionChanges as $key => $value) {
                $options[$key] = $value;
            }

            $updatedRestore = $this->restoreService->create(
                $restore->getAssetKey(),
                $restore->getPoint(),
                RestoreType::EXPORT,
                $this->timeService->getTime(),
                $options
            );
            $this->restoreService->update($updatedRestore);
            $this->restoreService->save();
        }
    }
}
