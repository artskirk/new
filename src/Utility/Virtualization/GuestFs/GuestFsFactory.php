<?php

namespace Datto\Utility\Virtualization\GuestFs;

/**
 * A simple factory class that returns instances of classes from this namespace.
 * The createGuestFs must be called first and launched before any other method
 * can succeed. The expected workflow is as follows:
 *  <code>
 *  $factory = new GuestFsFactory();
 *  $guestFs = $factory->initialize(['/tmp/disk1.img', '/tmp/disk2.img']);
 *  $osInspector = $factory->createOsInspector();
 *  if ($osInspector->inspect()) {
 *      $arch = $osInspector->getArch(); // return arch of the GuestOs.
 *  }
 *  </code>
 */
class GuestFsFactory
{
    private ?GuestFs $guestFs = null;
    private ?PartitionManager $partitionManager = null;
    private ?OsInspector $osInspector = null;
    private ?FileManager $fileManager = null;

    /**
     * Initializes the GuestFs Factory, constructing and initializing the underlying
     * libguestfs library. This must be called before calling any of the subsequent
     * `createManager()` functions defined in this file.
     *
     * @return GuestFs
     */
    public function initialize(array $drivePaths, bool $readOnly = true): GuestFs
    {
        if (null === $this->guestFs) {
            // Construct a new GuestFs to be used by the remaining helper classes
            $this->guestFs = new GuestFs($drivePaths, $readOnly);
        }
        return $this->guestFs;
    }

    /**
     * Returns PartitionManager instance, creates one if needed.
     *
     * The createGuestFs must be already called and the appliance be launched.
     *
     * @return PartitionManager
     */
    public function createPartitionManager(): PartitionManager
    {
        if (null === $this->partitionManager) {
            $this->partitionManager = new PartitionManager($this->guestFs);
        }

        return $this->partitionManager;
    }

    /**
     * Returns OsInspector instance, creates one if needed.
     *
     * The createGuestFs must be already called and the appliance be launched.
     *
     * @return OsInspector
     */
    public function createOsInspector(): OsInspector
    {
        if (null === $this->osInspector) {
            $this->osInspector = new OsInspector($this->guestFs);
        }

        return $this->osInspector;
    }

    /**
     * Returns FileManager instance, creates one if needed.
     *
     * The createGuestFs must be already called and the appliance be launched.
     *
     * @return FileManager
     */
    public function createFileManager(): FileManager
    {
        if (null === $this->fileManager) {
            $this->fileManager = new FileManager($this->guestFs);
        }

        return $this->fileManager;
    }
}
