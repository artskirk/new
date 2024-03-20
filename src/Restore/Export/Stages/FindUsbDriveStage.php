<?php

namespace Datto\Restore\Export\Stages;

use Datto\Restore\Export\ExportManager;
use Datto\Restore\Export\Stages\AbstractStage;

/**
 * Find the inserted USB drive and get its capacity.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class FindUsbDriveStage extends AbstractStage
{
    /** @var ExportManager */
    private $exportManager;

    public function __construct(ExportManager $exportManager)
    {
        $this->exportManager = $exportManager;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $drive = $this->exportManager->getUsbInformation();
        $this->context->setUsbInformation($drive['disk'], $drive['size']);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
    }
}
