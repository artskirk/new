<?php

namespace Datto\Restore\Export\Stages;

use Datto\Filesystem\TransparentMount;

/**
 * Manage creation of transparent mount points for USB export.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CreateTransparentMountUsbStage extends AbstractStage
{
    /** @var TransparentMount */
    protected $transparentMount;

    public function __construct(TransparentMount $transparentMount)
    {
        $this->transparentMount = $transparentMount;
    }

    public function commit()
    {
        $this->transparentMount->createTransparentMount($this->context->getCloneMountPoint());
    }

    public function cleanup()
    {
        $killProcess = true;
        $this->transparentMount->removeTransparentMount($this->context->getCloneMountPoint(), $killProcess);
    }

    public function rollback()
    {
        $killProcess = false;
        $this->transparentMount->removeTransparentMount($this->context->getCloneMountPoint(), $killProcess);
    }
}
