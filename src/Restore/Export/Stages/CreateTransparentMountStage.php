<?php

namespace Datto\Restore\Export\Stages;

use Datto\Filesystem\TransparentMount;

/**
 * This stage is responsible for making any encrypted images transparent.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateTransparentMountStage extends AbstractStage
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
        // nothing
    }

    public function rollback()
    {
        $killProcess = false;
        $this->transparentMount->removeTransparentMount($this->context->getCloneMountPoint(), $killProcess);
    }
}
