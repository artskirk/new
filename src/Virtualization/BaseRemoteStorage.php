<?php

namespace Datto\Virtualization;

use Datto\Config\Virtualization\VirtualDisks;
use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\IscsiTargetException;
use Datto\Iscsi\IscsiTargetExistsException;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base class for Hypervisor Remote Storage
 * Provides support for iscsi
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class BaseRemoteStorage implements RemoteHypervisorStorageInterface
{
    /** @var IscsiTarget */
    protected $iscsiTarget;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param IscsiTarget $iscsiTarget
     * @param DeviceLoggerInterface $logger
     */
    protected function __construct(
        IscsiTarget $iscsiTarget,
        DeviceLoggerInterface $logger
    ) {
        $this->iscsiTarget = $iscsiTarget;
        $this->logger = $logger;
    }

    /**
     * Create an iscsi target on the SIRIS
     *
     * @param string $uniqueName a unique name that will become part of the iscsi target name
     * @param VirtualDisks $disks the virtual disks to expose as LUNs on the iscsi target
     * @return string iscsi target name
     */
    protected function createLocalIscsiTarget(string $uniqueName, VirtualDisks $disks): string
    {
        try {
            $targetName = $this->iscsiTarget->makeTargetName($uniqueName);
            try {
                $this->iscsiTarget->createTarget($targetName);
            } catch (IscsiTargetExistsException $ite) {
                return $targetName;
            }

            foreach ($disks as $drive) {
                $vmdk = $drive->getVmdkFileName();
                $sn = $this->makeDiskSerialNumber($uniqueName, $vmdk);
                $localRawPath = sprintf(
                    '%s/%s',
                    $drive->getStorageLocation(),
                    $drive->getRawFileName()
                );

                $this->iscsiTarget->addLun($targetName, $localRawPath, false, false, $sn);
            }

            $this->iscsiTarget->writeChanges();
            return $targetName;
        } catch (IscsiTargetException $e) {
            $this->logger->error('BRS0001 Error creating local iSCSI target', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * Destroy the given target
     *
     * @param string $targetName
     */
    protected function destroyLocalIscsiTarget(string $targetName)
    {
        $this->iscsiTarget->closeSessionsOnTarget($targetName);
        $this->iscsiTarget->deleteTarget($targetName);
        $this->iscsiTarget->writeChanges();
    }

    /**
     * Generates a pseudo-hash for ScsiSn to set in LUN options.
     *
     * Since the ScsiSN must be at most 16 characters long none of the hashing
     * functions will generate such string. Therefore, this function is using
     * crc32b that generates 8 characters long checksums and the manipulates the
     * input params in such a way to get 16 character pseudo-hash which is
     * unique enough for this purpose.
     *
     * @param string $uniqueName
     * @param string $disk
     *
     * @return string
     *  A 16 character long pseudo-hash from the input parameters.
     */
    protected function makeDiskSerialNumber(string $uniqueName, string $disk): string
    {
        $full = $uniqueName . $disk;

        $half = (int) floor(strlen($full) / 2);
        $first = substr($full, 0, $half);
        $second = substr($full, $half);

        return hash('crc32b', $first, false) . hash('crc32b', $second, false);
    }
}
