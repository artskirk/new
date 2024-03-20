<?php

namespace Datto\Restore\Export\Stages;

/**
 * This stage is responsible for sharing converted images over samba and nfs.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateNetworkShareStage extends AbstractNetworkShareStage
{
    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $mountPoint = $this->context->getMountPoint();
        $authorizedUser = $this->context->getAgent()
            ->getShareAuth()
            ->getUser();

        $this->setPermissionsForSharing();

        $this->createSambaShare($mountPoint, $authorizedUser);
        $this->createNfs($mountPoint, $authorizedUser);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $shareName = $this->getShareName();
        $mountPoint = $this->context->getMountPoint();

        $this->sambaManager->removeShare($shareName);
        $this->nfs->disable($mountPoint);
    }

    /**
     * Allow r/w access to the files that we will share
     */
    private function setPermissionsForSharing()
    {
        $files = $this->filesystem->glob($this->context->getMountPoint() . '/*');
        foreach ($files as $file) {
            $this->filesystem->chmod($file, 0666);
        }
    }

    /**
     * Create a samba share that points to the image export mount point.
     *
     * @param string $mountPoint
     * @param string $authorizedUser
     */
    private function createSambaShare($mountPoint, $authorizedUser)
    {
        // remove if existing to support repair use case
        if ($this->sambaManager->doesShareExist($this->getShareName())) {
            $this->sambaManager->removeShare($this->getShareName());
        }

        // Create samba share
        $share = $this->sambaManager->createShare($this->getShareName(), $mountPoint);

        // Allow symlinks for encrypted agents (necessary for use with transmnt)
        $global = $this->sambaManager->getSectionByName('global');
        $global->setProperty('allow insecure wide links', 'yes');

        $properties = array();
        $properties['force user'] = 'nobody';
        $properties['create mask'] = '0777';
        $properties['directory mask'] = '0777';
        $properties['oplocks'] = '0';
        $properties['level2 oplocks'] = 0;
        $properties['admin users'] = '';
        $properties['read only'] = 'yes';
        $properties['follow symlinks'] = 'yes';
        $properties['wide links'] = 'yes';

        if (!empty($authorizedUser)) {
            $properties['public'] = 'no';
            $properties['guest ok'] = 'no';
            $properties['valid users'] = $authorizedUser;
        } else {
            $properties['public'] = 'yes';
            $properties['guest ok'] = 'yes';
            $properties['valid users'] = '';
        }

        $share->setProperties($properties);
        $this->sambaManager->sync();
    }

    /**
     * Create a NFS that points to the image export mount point, only if no authorized user exists.
     *
     * @param string $mountPoint
     * @param string $authorizedUser
     */
    private function createNfs($mountPoint, $authorizedUser)
    {
        // remove if existing to support repair use case
        // NFS starts before stitchfs is mounted, so it has a stale file handle, remove/re-add after stitchfs fixes it
        if ($this->nfs->isEnabled($mountPoint)) {
            $this->nfs->disable($mountPoint);
        }

        $hasAuthorizedUser = !empty($authorizedUser);

        // only export over NFS if no auth user is set
        if (!$hasAuthorizedUser) {
            $this->nfs->enable($mountPoint);
        }
    }
}
