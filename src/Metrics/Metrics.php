<?php

namespace Datto\Metrics;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Metrics
{
    // Counters
    const SYSTEM_REBOOT = 'system.reboot';

    const RESTORE_STARTED = 'restore.started';
    const RESTORE_BMR_TRANSFER_STARTED = 'restore.bmr.transfer.started';
    const RESTORE_BMR_TRANSFER_SUCCESS = 'restore.bmr.transfer.success';
    const RESTORE_BMR_TRANSFER_FAILURE = 'restore.bmr.transfer.fail';
    const RESTORE_BMR_MIRROR_STARTED = 'restore.bmr.mirror.started';
    const RESTORE_BMR_MIRROR_SUCCESS = 'restore.bmr.mirror.success';
    const RESTORE_BMR_MIRROR_FAILURE = 'restore.bmr.mirror.fail';
    const RESTORE_BMR_HIR_STARTED = 'restore.bmr.hir.started';
    const RESTORE_BMR_HIR_SUCCESS = 'restore.bmr.hir.success';
    const RESTORE_BMR_HIR_FAILURE = 'restore.bmr.hir.fail';
    const RESTORE_BMR_PROCESS_SUCCESS = 'restore.bmr.process.success';
    const RESTORE_BMR_PROCESS_FAILURE = 'restore.bmr.process.fail';
    const RESTORE_IMAGE_EXPORT_NETWORK_STARTED = 'restore.image.export.network.started';
    const RESTORE_IMAGE_EXPORT_USB_STARTED = 'restore.image.export.usb.started';

    const OFFSITE_POINT_QUEUED = 'offsite.point.queued';
    const OFFSITE_POINT_COMPLETED = 'offsite.point.completed';

    const AGENT_VERIFICATION_PROCESS = 'agent.verification.process';
    const AGENT_VERIFICATION_DURATION = 'agent.verification.duration';
    const AGENT_VERIFICATION_PROCESS_CREATION_FAILURE = 'agent.verification.process.creation.failure';

    const AGENT_BACKUP_STARTED = 'agent.backup.started';
    const AGENT_BACKUP_SUCCESS = 'agent.backup.success';
    const AGENT_BACKUP_FAIL = 'agent.backup.fail';
    const AGENT_BACKUP_DURATION = 'agent.backup.duration';
    const AGENT_BACKUP_LOG_LEVEL_FORMAT = 'agent.backup.log.%s';
    const AGENT_BACKUP_TYPE_FORMAT = 'agent.backup.type.%s';
    const AGENT_BACKUP_ISCSI_FALLBACK = 'agent.backup.iscsi_fallback';

    const ASSET_BACKUP_SCHEDULED = 'asset.backup.scheduled';
    const ASSET_BACKUP_SCHEDULE_FAILED = 'asset.backup.schedule_failed';
    const ASSET_BACKUP_STARTED = 'asset.backup.started';
    const ASSET_BACKUP_REMOVED_FROM_SCHEDULE = 'asset.backup.removed_from_schedule';
    const ASSET_BACKUP_TIME_IN_SCHEDULE = 'asset.backup.time_in_schedule';

    const BACKUP_SCHEDULE_QUEUE_LENGTH = 'backup.schedule.queue.length';

    const DRIVES_ACTIVE = 'drives.active';
    const DRIVES_WITH_ERRORS = 'drives.with_errors';
    const DRIVES_MISSING = 'drives.missing';

    const DTC_AGENT_CREATE_STARTED = 'dtc.agent.create.started';
    const DTC_AGENT_CREATE_SUCCESS = 'dtc.agent.create.success';
    const DTC_AGENT_CREATE_FAILED = 'dtc.agent.create.failed';
    const DTC_AGENT_CREATE_INVALID = 'dtc.agent.create.invalid';

    const DTC_AGENT_BACKUP_SET_STATUS = 'dtc.agent.backup.set_status';
    const DTC_AGENT_BACKUP_STARTED = 'dtc.agent.backup.started';
    const DTC_AGENT_BACKUP_SUCCESS = 'dtc.agent.backup.success';
    const DTC_AGENT_BACKUP_FAIL = 'dtc.agent.backup.fail';
    const DTC_AGENT_BACKUP_PREPARE_SUCCESS = 'dtc.agent.backup.prepare.success';
    const DTC_AGENT_BACKUP_PREPARE_FAIL = 'dtc.agent.backup.prepare.fail';
    const DTC_AGENT_CHECKIN = 'dtc.agent.checkin';
    const DTC_AGENT_DATASET_UNMOUNTED = 'dtc.agent.dataset.unmounted';
    const DTC_AGENT_BACKUP_RATIO = 'dtc.agent.ratio.backup';
    const DTC_AGENT_TRANSFER_RATIO = 'dtc.agent.ratio.transfer';
    const DTC_OFFSITE_RETENTION_COUNT = 'dtc.offsite.retention.count';
    const DTC_AGENT_NEW_VOLUME_DISCOVERED = 'dtc.agent.new.volume.discovered';

    const INSIGHTS_STARTED = 'backup-insights.started';
    const INSIGHTS_SUCCESS = 'backup-insights.success';
    const INSIGHTS_FAILURE = 'backup-insights.failure';

    const DEVICE_MIGRATION_STARTED = 'migration.device.started';
    const DEVICE_MIGRATION_SUCCESS = 'migration.device.success';
    const DEVICE_MIGRATION_FAILURE = 'migration.device.failure';

    const CONFIG_BACKUP_FAILED = 'config.backup.failed';
    const CONFIG_BACKUP_PARTIAL_FAILED = 'config.backup.partial.failed';

    const CLOUD_FEATURE_GET_ALL_FAILED = 'cloud.feature.getall.failed';

    const AGENT_RANSOMWARE_PROCESS = 'agent.ransomware.process';
    const AGENT_FILESYSTEM_INTEGRITY_PROCESS = 'agent.filesystem.integrity.process';
    const AGENT_MISSING_VOLUME_PROCESS = 'agent.missing_volume.process';

    // Tags
    const RESTORE_TYPE_VIRT_LOCAL = 'active-local';
    const RESTORE_TYPE_VIRT_HYPERVISOR = 'active-hypervisor';
    const RESTORE_TYPE_VIRT_HYBRID = 'hybrid-virt';
    const RESTORE_TYPE_ESX_UPLOAD = 'esx-upload';
    const RESTORE_TYPE_IMAGE_EXPORT_NETWORK = 'export-network';
    const RESTORE_TYPE_IMAGE_EXPORT_USB = 'export-usb';
    const RESTORE_TYPE_FILE_RESTORE = 'file';
    const RESTORE_TYPE_VOLUME_ISCSI = 'iscsimounter';
    const RESTORE_TYPE_ISCSI_ROLLBACK = 'iscsi-rollback';

    // Statistics
    const STATISTICS_CERTIFICATE_SECONDS_UNTIL_EXPIRED = 'statistics.certificate.seconds_until_expired';

    const STATISTIC_AGENT_CRASH_DUMPS = 'statistics.agent.crash_dumps';
    const STATISTIC_AGENT_DATASET_ORPHAN = 'statistics.agent.dataset.orphan';
    const STATISTIC_RESTORE_ACTIVE = 'statistics.restore.active';
    const STATISTIC_RESTORE_ORPHAN = 'statistics.restore.orphan';
    const STATISTIC_SHARE_DATASET_ORPHAN = 'statistics.share.dataset.orphan';
    const STATISTIC_VERIFICATION_QUEUE_LENGTH = 'statistics.verification.queue_length';
    const STATISTIC_MAINTENANCE_MODE = 'statistics.maintenance_mode';
    const STATISTIC_VIRTUAL_MACHINE_STATES = 'statistics.virtual_machine.states';

    const STATISTIC_DTC_AGENT_PROVISIONED = 'statistics.dtc.agent.provisioned';
    const STATISTIC_DTC_AGENT_HAS_BACKUP_IN_LAST_DAY = 'statistics.dtc.agent.active.has_backup_in_last_day';
    const STATISTIC_DTC_AGENT_HAS_BACKUP_IN_LAST_WEEK = 'statistics.dtc.agent.active.has_backup_in_last_week';
    const STATISTIC_DTC_AGENT_BACKUPS_IN_LAST_DAY = 'statistics.dtc.agent.active.backups_in_last_day';
    const STATISTIC_DTC_AGENT_BACKUPS_IN_LAST_WEEK = 'statistics.dtc.agent.active.backups_in_last_week';
    const STATISTIC_DTC_AGENT_BACKUPS_IN_TOTAL = 'statistics.dtc.agent.backups_in_total';
    const STATISTIC_DTC_AGENT_HAS_CHECKIN_IN_LAST_DAY = 'statistics.dtc.agent.active.has_checkin_in_last_day';
    const STATISTIC_DTC_AGENT_HAS_CHECKIN_IN_LAST_WEEK = 'statistics.dtc.agent.has_checkin_in_last_week';
    const STATISTIC_DTC_AGENT_HAS_BACKUP = "statistics.dtc.agent.active.has_backup";
    const STATISTIC_DTC_AGENT_LATEST_SCREENSHOT_SUCCESS = 'statistics.dtc.agent.active.last_verification_successful';
    const STATISTIC_DTC_ACTIVE_AGENTS = "statistics.dtc.agent.active";
    const STATISTIC_DTC_SYSTEMD_SERVICES = 'statistics.dtc.systemd.services';
    const STATISTIC_DTC_COMMANDER_PROCESSES = 'statistics.dtc.dtccommander.processes';
    const STATISTIC_DTC_ACTIVE_BACKUPS_ESTIMATED = 'statistics.dtc.agent.active_backups_estimated';
    const STATISTIC_RETENTION_LOCAL_REMAINING_COUNT = 'statistics.retention.local.remaining.count';
    const STATISTIC_RETENTION_OFFSITE_REMAINING_COUNT = 'statistics.retention.offsite.remaining.count';

    const STATISTIC_DTC_ACTIVE_BACKUP_TYPES = 'statistics.dtc.agent.active_backup_types_estimated';

    const STATISTIC_ZFS_POOL_CAPACITY = 'statistics.zfs.pool.capacity';
    const STATISTIC_ZFS_POOL_FREE = 'statistics.zfs.pool.free';
    const STATISTIC_ZFS_POOL_SIZE = 'statistics.zfs.pool.size';
    const STATISTIC_ZFS_POOL_ALLOCATED = 'statistics.zfs.pool.allocated';
    const STATISTIC_ZFS_POOL_DEDUPRATIO = 'statistics.zfs.pool.dedupratio';
    const STATISTIC_ZFS_POOL_FRAGMENTATION = 'statistics.zfs.pool.fragmentation';
    const STATISTIC_ZFS_POOL_EXPANSION_PROCESS_DURATION = 'statistics.zfs.pool.expansion.process.duration';
    const STATISTIC_ZFS_POOL_DISKS_COUNT = 'statistics.zfs.pool.disks.count';

    const STATISTICS_ZFS_DATASETS_NOT_MOUNTED = 'statistics.zfs.datasets.not_mounted';

    const STATISTICS_STORAGE_DISK_MISSING_GENERATED_KEY = 'statistics.storage.disk.missing_generated_key';
    const STATISTICS_STORAGE_DISK_LOCKED = 'statistics.storage.disk.locked';

    const STATISTIC_HEALTH_ZPOOL = 'statistics.health.zpool';
    const STATISTIC_HEALTH_MEMORY = 'statistics.health.memory';
    const STATISTIC_HEALTH_CPU = 'statistics.health.cpu';
    const STATISTIC_HEALTH_IOPS = 'statistics.health.iops';

    // ZFS
    const ZFS_CACHE_WRITE_FAIL = 'zfs.cache.write.failure';
    const ZFS_CLONE_FAIL = 'zfs.clone.generic.failure';
    const ZFS_CLONE_NOT_MOUNTED = 'zfs.clone.property.not_mounted';
    const ZFS_CLONE_BAD_MOUNT = 'zfs.clone.bad_mount';

    // Alerts
    const ALERT_SENT = 'alerts.sent';
    const ALERT_FAILURE = 'alerts.failure';
}
