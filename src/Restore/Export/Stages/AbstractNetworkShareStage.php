<?php

namespace Datto\Restore\Export\Stages;

use Datto\Nfs\NfsExportManager;
use Datto\Common\Utility\Filesystem;
use Datto\Samba\SambaManager;

/**
 * Base class for any shared functionality between network sharing stages.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractNetworkShareStage extends AbstractStage
{
    /** @var SambaManager */
    protected $sambaManager;

    /** @var NfsExportManager */
    protected $nfs;

    /** @var Filesystem */
    protected $filesystem;

    public function __construct(
        SambaManager $sambaManager,
        NfsExportManager $nfs,
        Filesystem $filesystem
    ) {
        $this->sambaManager = $sambaManager;
        $this->nfs = $nfs;
        $this->filesystem = $filesystem;
    }

    /**
     * Get the desired name of the share.
     *
     * @return string
     */
    protected function getShareName()
    {
        return strtoupper(sprintf(
            '%s-%s-%s',
            $this->context->getAgent()->getHostname(),
            $this->context->getSnapshot(),
            $this->context->getImageType()->value()
        ));
    }
}
