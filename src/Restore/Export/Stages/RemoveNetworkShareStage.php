<?php

namespace Datto\Restore\Export\Stages;

/**
 * This stage is responsible for removing any network shares that were created during an export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoveNetworkShareStage extends AbstractNetworkShareStage
{
    public function commit()
    {
        $this->sambaManager->removeShare($this->getShareName());
        $this->nfs->disable($this->context->getMountPoint());
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
