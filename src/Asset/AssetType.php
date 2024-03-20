<?php
namespace Datto\Asset;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\Generic\GenericAgentless;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent as LinuxAgentless;
use Datto\Asset\Agent\Agentless\Windows\WindowsAgent as WindowsAgentless;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\Zfs\ZfsShare;
use Exception;

/**
 * Enum-style class that declares the possible asset types.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AssetType
{
    const SHARE = 'share';
    const NAS_SHARE = 'nas';
    const ISCSI_SHARE = 'iscsi';
    const EXTERNAL_NAS_SHARE = 'externalNas';
    const ZFS_SHARE = 'zfs';
    const AGENT = 'agent';

    // The cloud relies on these constants (in the 'type' field of the agentInfo file saved in the agent's snapshot).
    // See the node-api and vmscripts repos
    const WINDOWS_AGENT = 'windows';
    const LINUX_AGENT = 'linux';
    /**
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining Mac related code. It is ok
     * to completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release
     * notes for the removal.
     */
    const MAC_AGENT = 'mac';
    const AGENTLESS_WINDOWS = 'agentlessWindows';
    const AGENTLESS_LINUX = 'agentlessLinux';

    const AGENTLESS_GENERIC = 'agentlessGeneric';
    const AGENTLESS = 'agentless';

    const AGENTLESS_TYPES = [
        self::AGENTLESS_WINDOWS,
        self::AGENTLESS_LINUX,
        self::AGENTLESS_GENERIC,
    ];

    /**
     * Returns the fully qualified class name that matches the
     * given asset type. The return value can be used to compare
     * with the 'instanceof' operator.
     *
     * @param string $type Asset type, e.g. AssetType::SHARE or AssetType::WINDOWS_AGENT
     * @return string Fully qualified class name, e.g. \Datto\Asset\Share\Share
     */
    public static function toClassName($type)
    {
        switch ($type) {
            case self::SHARE:
                return Share::class;
            case self::NAS_SHARE:
                return NasShare::class;
            case self::ISCSI_SHARE:
                return IscsiShare::class;
            case self::EXTERNAL_NAS_SHARE:
                return ExternalNasShare::class;
            case self::ZFS_SHARE:
                return ZfsShare::class;
            case self::AGENT:
                return Agent::class;
            case self::WINDOWS_AGENT:
                return WindowsAgent::class;
            case self::LINUX_AGENT:
                return LinuxAgent::class;
            case self::MAC_AGENT:
                return MacAgent::class;
            case self::AGENTLESS_WINDOWS:
                return WindowsAgentless::class;
            case self::AGENTLESS_LINUX:
                return LinuxAgentless::class;
            case self::AGENTLESS_GENERIC:
                return GenericAgentless::class;
            case self::AGENTLESS:
                return AgentlessSystem::class;
            default:
                throw new \Exception('Unknown asset type: "' . $type . '"');
        }
    }

    /**
     * Check whether the type is a share
     *
     * @param string $type An AssetType constant
     * @return bool True if $type is a share, false if it is not
     */
    public static function isShare($type): bool
    {
        return in_array($type, [self::SHARE, self::NAS_SHARE, self::ISCSI_SHARE, self::EXTERNAL_NAS_SHARE, self::ZFS_SHARE], true);
    }

    /**
     * Checks if the specified $agentInfo identifies an asset as $wantedType
     *
     * @param string $wantedType (should be an AssetType constant)
     * @param array $agentInfo The unserialized agentInfo of the asset you want to check
     * @return bool True if the $agentInfo looks like a $wantedType asset, otherwise false.
     */
    public static function isType($wantedType, array $agentInfo): bool
    {
        $type = $agentInfo['type'] ?? null;

        $isShare = $type === 'snapnas';
        $shareType = $agentInfo['shareType'] ?? null;

        $isZfsShare = $isShare && $shareType === self::ZFS_SHARE;
        $isExternalNas = $isShare && $shareType === self::EXTERNAL_NAS_SHARE;
        $isIscsi = $isShare && ($shareType === self::ISCSI_SHARE || ($agentInfo['isIscsi'] ?? false));
        $isNasShare = $isShare && ($shareType === self::NAS_SHARE || (!$isIscsi && !$isExternalNas && !$isZfsShare));

        $isAgentless = !$isShare && (in_array($type, self::AGENTLESS_TYPES, true) || stripos($agentInfo['agentVersion'] ?? '', self::AGENTLESS) !== false);
        $isAgent = !$isShare;

        $isAgentlessGeneric = $isAgentless && $type === self::AGENTLESS_GENERIC;
        $isAgentlessLinux = $isAgentless && (!$isAgentlessGeneric && isset($agentInfo['kernel']) || $type === self::AGENTLESS_LINUX);
        $isAgentlessWindows = $isAgentless && (!$isAgentlessGeneric && !$isAgentlessLinux || $type === self::AGENTLESS_WINDOWS);

        $isLinuxAgent = $isAgent && !$isAgentless && (isset($agentInfo['kernel']) || $type === self::LINUX_AGENT);
        $isMacAgent = $isAgent && $type === self::MAC_AGENT;
        $isWindowsAgent = $isAgent && !$isAgentless && !$isLinuxAgent && !$isMacAgent;

        $type = [
            self::SHARE => $isShare,
            self::NAS_SHARE => $isNasShare,
            self::ISCSI_SHARE => $isIscsi,
            self::EXTERNAL_NAS_SHARE => $isExternalNas,
            self::ZFS_SHARE => $isZfsShare,
            self::AGENT => $isAgent,
            self::WINDOWS_AGENT => $isWindowsAgent,
            self::LINUX_AGENT => $isLinuxAgent,
            self::MAC_AGENT => $isMacAgent,
            self::AGENTLESS => $isAgentless,
            self::AGENTLESS_WINDOWS => $isAgentlessWindows,
            self::AGENTLESS_LINUX => $isAgentlessLinux,
            self::AGENTLESS_GENERIC => $isAgentlessGeneric,
        ];

        return $type[$wantedType] ?? false;
    }

    /**
     * Gets the AgentPlatform for the asset
     *
     * @param array $agentInfo The unserialized agentInfo of the asset you want to check
     * @param bool $isDirectToCloud Should pass true if the directToCloudAgentSettings key file exists for this asset
     * @param bool $isShadowsnap Should pass true if the shadowSnap key file exists for this asset
     * @return AgentPlatform|null The AgentPlatform for the agent, or null if the asset is a share
     */
    public static function getAgentPlatform(array $agentInfo, bool $isDirectToCloud, bool $isShadowsnap)
    {
        if ($isDirectToCloud) {
            return AgentPlatform::DIRECT_TO_CLOUD();
        }

        if ($isShadowsnap) {
            return AgentPlatform::SHADOWSNAP();
        }

        if (static::isType(self::AGENTLESS_GENERIC, $agentInfo)) {
            return AgentPlatform::AGENTLESS_GENERIC();
        }

        if (static::isType(self::AGENTLESS, $agentInfo)) {
            return AgentPlatform::AGENTLESS();
        }

        if (static::isType(self::WINDOWS_AGENT, $agentInfo)) {
            return AgentPlatform::DATTO_WINDOWS_AGENT();
        }

        if (static::isType(self::LINUX_AGENT, $agentInfo)) {
            return AgentPlatform::DATTO_LINUX_AGENT();
        }

        if (static::isType(self::MAC_AGENT, $agentInfo)) {
            return AgentPlatform::DATTO_MAC_AGENT();
        }

        return null; // not an agent
    }

    /**
     * @param DatasetPurpose $datasetPurpose
     * @param AgentPlatform|null $agentPlatform
     * @param string|null $os
     * @return string value from AssetType
     */
    public static function determineAssetType(
        DatasetPurpose $datasetPurpose = null,
        AgentPlatform $agentPlatform = null,
        string $os = null
    ): string {
        if (is_null($datasetPurpose)) {
            throw new Exception('DatasetPurpose is required for replicated asset creation.');
        }

        switch ($datasetPurpose) {
            case DatasetPurpose::NAS_SHARE():
                return AssetType::NAS_SHARE;
            case DatasetPurpose::EXTERNAL_SHARE():
                return AssetType::EXTERNAL_NAS_SHARE;
            case DatasetPurpose::ISCSI_SHARE():
                return AssetType::ISCSI_SHARE;
        }

        if (in_array($datasetPurpose, [DatasetPurpose::AGENT(), DatasetPurpose::RESCUE_AGENT()])) {
            if (is_null($agentPlatform)) {
                throw new Exception("AgentPlatform is required for replicated agent creation.");
            }

            switch ($agentPlatform) {
                case AgentPlatform::SHADOWSNAP():
                case AgentPlatform::DATTO_WINDOWS_AGENT():
                    return AssetType::WINDOWS_AGENT;
                case AgentPlatform::DATTO_MAC_AGENT():
                    /** @psalm-suppress DeprecatedConstant */
                    return AssetType::MAC_AGENT;
                case AgentPlatform::DATTO_LINUX_AGENT():
                    return AssetType::LINUX_AGENT;
                case AgentPlatform::AGENTLESS():
                    if (!empty($os) && self::isWindows($os)) {
                        return AssetType::AGENTLESS_WINDOWS;
                    }
                    return AssetType::AGENTLESS_LINUX;
                case AgentPlatform::AGENTLESS_GENERIC():
                    return AssetType::AGENTLESS_GENERIC;
                case AgentPlatform::DIRECT_TO_CLOUD():
                    if (!empty($os) && self::isWindows($os)) {
                        return AssetType::WINDOWS_AGENT;
                    }
                    return AssetType::LINUX_AGENT;
            }
        }

        $agentPlatformKey = $agentPlatform ? $agentPlatform->value() : "";
        throw new Exception("Cannot determine asset type for DatasetPurpose '{$datasetPurpose->key()}', "
            . "AgentPlatform '$agentPlatformKey', OS '$os'.");
    }

    public static function isWindows(string $os): int
    {
        return preg_match('/windows/i', $os);
    }
}
