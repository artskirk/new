<?php

namespace Datto\Restore;

use Datto\Asset\AssetType;
use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 *
 * @method static RestoreType FILE()
 * @method static RestoreType PUSH_FILE()
 * @method static RestoreType ACTIVE_VIRT()
 * @method static RestoreType HYBRID_VIRT()
 * @method static RestoreType EXPORT()
 * @method static RestoreType RESCUE()
 * @method static RestoreType ESX_UPLOAD()
 * @method static RestoreType ESX_VIRT()
 * @method static RestoreType VOLUME_RESTORE()
 * @method static RestoreType ISCSI_RESTORE()
 * @method static RestoreType DIFFERENTIAL_ROLLBACK()
 * @method static RestoreType BMR()
 */
final class RestoreType extends AbstractEnumeration
{
    // When adding a new restore type, do not use dashes. Dashes are used as the delimiter for fields within the clone name and can cause parsing issues.
    // For example in /homePool/0ca5e2a85ea84b7988cd470688ad1ee2-1622053845-file the fields are <agent key>-<snapshot epoch>-<restore suffix>
    const FILE = 'file';
    const PUSH_FILE = 'pushFile';
    const ACTIVE_VIRT = 'active';
    const HYBRID_VIRT = 'hybrid-virt';
    const EXPORT = 'export';
    const RESCUE = 'rescue';
    const VHD = 'vhd';
    const ESX_UPLOAD = 'esx-upload';
    const ESX_VIRT = 'esxVirt';
    const VOLUME_RESTORE = 'volumeRestore';
    const ISCSI_RESTORE = 'iscsimounter';
    const DIFFERENTIAL_ROLLBACK = 'differential-rollback';
    const BMR = 'bmr';
    const WINDOWS_FILESYSTEM = 'windows-fs';

    const NON_USER_MANAGEABLE_RESTORE_TYPES = [
        RestoreType::BMR,
        RestoreType::DIFFERENTIAL_ROLLBACK
    ];

    const NON_USER_REMOVABLE_RESTORE_TYPES = [
        RestoreType::BMR,
        RestoreType::HYBRID_VIRT,
        RestoreType::RESCUE,
        RestoreType::DIFFERENTIAL_ROLLBACK
    ];

    const VIRTUALIZATIONS = [
        RestoreType::ACTIVE_VIRT,
        RestoreType::HYBRID_VIRT,
        RestoreType::RESCUE
    ];
}
