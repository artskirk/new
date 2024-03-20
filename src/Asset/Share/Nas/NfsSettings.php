<?php
namespace Datto\Asset\Share\Nas;

use Datto\Asset\Share\ShareException;
use Datto\Nfs\NfsExportManager;
use Datto\Samba\SambaManager;

/**
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class NfsSettings extends AbstractSettings
{
    const DEFAULT_ENABLED = false;

    /** @var bool */
    private $enabled;

    /** @var NfsExportManager */
    private $manager;

    /**
     * @param string $name
     * @param SambaManager $samba
     * @param NfsExportManager $manager
     * @param bool $enabled
     */
    public function __construct(
        $name,
        SambaManager $samba,
        NfsExportManager $manager = null,
        $enabled = self::DEFAULT_ENABLED
    ) {
        parent::__construct($name, $samba);

        $this->manager = $manager ?: new NfsExportManager();
        $this->enabled = $enabled;
    }

    /**
     * Returns whether or not NFS is enabled for this share
     *
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Enables NFS for the share, and add all currently enabled
     * Samba users to it. This method will restart the 'netatalk' service
     * to enable the changes.
     *
     */
    public function enable(): void
    {
        $success = $this->manager->enable($this->mountPath);
        if (!$success) {
            throw new ShareException('Cannot enable NFS.');
        }

        $this->enabled = true;
    }

    /**
     * Disable NFS for the share, and removes all Samba users from it.
     * This method will restart the 'netatalk' service to enable the changes.
     *
     */
    public function disable(): void
    {
        $success = $this->manager->disable($this->mountPath);
        if (!$success) {
            throw new ShareException('Cannot disable NFS.');
        }

        $this->enabled = false;
    }

    /**
     * Copy the NfsSettings from an existing NasShare's NfsSettings.
     *
     * @param NfsSettings $from the NfsSettings from an existing NasShare's NfsSettings
     */
    public function copyFrom(NfsSettings $from): void
    {
        if ($from->isEnabled()) {
            $this->enable();
        } else {
            $this->disable();
        }
    }
}
