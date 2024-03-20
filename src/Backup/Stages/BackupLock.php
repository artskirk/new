<?php

namespace Datto\Backup\Stages;

/**
 * This backup stage acquires and releases the backup lock.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupLock extends BackupStage
{
    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->context->getLogger()->debug('BAK0001 Acquiring backup lock');
        $this->context->getBackupLock()->acquire();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->context->getLogger()->debug('BAK0002 Releasing backup lock');
        $this->context->getBackupLock()->release();
    }
}
