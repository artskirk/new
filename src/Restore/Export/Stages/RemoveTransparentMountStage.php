<?php

namespace Datto\Restore\Export\Stages;

use Datto\Filesystem\TransparentMount;

/**
 * This stage is responsible for removing any transparent mounts used during an export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoveTransparentMountStage extends AbstractStage
{
    /** @var TransparentMount */
    protected $transparentMount;

    public function __construct(TransparentMount $transparentMount)
    {
        $this->transparentMount = $transparentMount;
    }

    public function commit()
    {
        $killProcess = true;
        $this->transparentMount->removeTransparentMount($this->context->getCloneMountPoint(), $killProcess);
    }

    public function cleanup()
    {
        // nothing
    }

    public function rollback()
    {
        // nothing
    }
}
