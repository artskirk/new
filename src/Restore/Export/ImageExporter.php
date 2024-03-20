<?php

namespace Datto\Restore\Export;

use Datto\ImageExport\BootType;
use Datto\ImageExport\Status;

/**
 * Interface for exporting an image. Internally, any implementations must handle the conversion process
 * of the raw .datto files into a desired format, as well as exporting it to the desired destination.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
interface ImageExporter
{
    /**
     * Export an image of a specific type.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     */
    public function export(string $agentName, int $snapshotEpoch);

    /**
     * Repair an image export (e.g. after reboot fix a network share)
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     */
    public function repair(string $agentName, int $snapshotEpoch);

    /**
     * Un-export an image that has been exported.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     */
    public function remove(string $agentName, int $snapshotEpoch);

    /**
     * Returns true if the image is exported, false otherwise.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @return bool
     */
    public function isExported(string $agentName, int $snapshotEpoch): bool;

    /**
     * Get the status of an export.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     * @return Status
     */
    public function getStatus(string $agentName, int $snapshotEpoch): Status;

    public function setBootType(BootType $bootType = null);
}
