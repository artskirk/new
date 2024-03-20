<?php
namespace Datto\Asset;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * These values are reported to cloud and end up in deviceVols.type and asset.assetType tables.
 * For reasons unknown, their values do not align AssetType.
 * Do not add, remove, or change these without coordinating with cloud teams.
 *
 * @method static DatasetPurpose SYSTEM()
 * @method static DatasetPurpose AGENT()
 * @method static DatasetPurpose RESCUE_AGENT()
 * @method static DatasetPurpose NAS_SHARE()
 * @method static DatasetPurpose ISCSI_SHARE()
 * @method static DatasetPurpose EXTERNAL_SHARE()
 * @method static DatasetPurpose ZFS_SHARE()
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class DatasetPurpose extends AbstractEnumeration
{
    const SYSTEM = 'system';
    const AGENT = 'siris';
    const RESCUE_AGENT = 'rescue';
    const NAS_SHARE = 'share';
    const ISCSI_SHARE = 'iscsi';
    const EXTERNAL_SHARE = 'externalShare';
    const ZFS_SHARE = 'zfsShare';
}
