<?php

namespace Datto\Restore;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Core\Storage\CloneCreationContext;
use InvalidArgumentException;

/**
 * Encapsulation of an Asset ZFS dataset clone
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class CloneSpec
{
    const CLONE_ZFS_BASE = 'homePool';
    const SHARE_ZFS_BASE = 'homePool/home';
    const AGENT_ZFS_BASE = 'homePool/home/agents';
    const AGENT_ZFS_MOUNT_ROOT = '/home/agents';

    /** @var string */
    private $assetKey;

    /** @var string */
    private $sourceDatasetName;

    /** @var string */
    private $snapshotName;

    /** @var string */
    private $targetDatasetName;

    /** @var string */
    private $targetMountpoint;

    /** @var string */
    private $suffix;

    /** @var bool|null */
    private $sync;

    /**
     * @param string $sourceZfsBase
     * @param string $assetKey
     * @param string $snapshotName
     * @param string $targetDatasetName
     * @param string $targetMountPoint
     * @param string $suffix
     * @param bool|null $sync
     */
    public function __construct(
        string $sourceZfsBase,
        string $assetKey,
        string $snapshotName,
        string $targetDatasetName,
        string $targetMountPoint,
        string $suffix,
        bool $sync = null
    ) {
        self::assertNotEmptyString('sourceZfsBase', $sourceZfsBase);
        self::assertNotEmptyString('assetKey', $assetKey);
        self::assertNotEmptyString('snapshotName', $snapshotName);
        self::assertNotEmptyString('targetDatasetName', $targetDatasetName);

        $this->assetKey = $assetKey;
        $this->sourceDatasetName = "$sourceZfsBase/$assetKey";
        $this->snapshotName = $snapshotName;
        $this->targetDatasetName = $targetDatasetName;
        $this->targetMountpoint = $targetMountPoint;
        $this->suffix = $suffix;
        $this->sync = $sync;
    }

    /**
     * Create a clone context for use by ZfsStorage::cloneSnapshot()
     *
     * @return CloneCreationContext
     */
    public function createCloneCreationContext(): CloneCreationContext
    {
        $cloneNameParts = explode('/', $this->getTargetDatasetName());
        $cloneName = array_pop($cloneNameParts);
        $cloneParentId = implode('/', $cloneNameParts);
        $cloneContext = new CloneCreationContext($cloneName, $cloneParentId, $this->targetMountpoint, $this->sync);
        return $cloneContext;
    }

    /**
     * Return clone source for creating snapshots
     *
     * @return string
     */
    public function getCloneSource(): string
    {
        return $this->sourceDatasetName . '@' . $this->snapshotName;
    }

    /**
     * Create a clone spec from a rescue agent instance
     *
     * @param Agent $agent
     * @return CloneSpec
     */
    public static function fromRescueAgent(Agent $agent): CloneSpec
    {
        if (!$agent->isRescueAgent()) {
            throw new InvalidArgumentException("Expected agent '{$agent->getKeyName()}' to be a rescue agent");
        }
        $settings = $agent->getRescueAgentSettings();
        return static::fromRescueAgentAttributes(
            $settings->getSourceAgentKeyName(),
            $settings->getSourceAgentSnapshotEpoch(),
            $agent->getKeyName()
        );
    }

    /**
     * Create a new clone spec for a rescue agent from its attributes
     *
     * @param string $sourceAgentKey key name of the source agent
     * @param string $snapshotName snapshot to clone from
     * @param string $rescueAgentKey key name of the rescue agent
     * @return CloneSpec
     */
    public static function fromRescueAgentAttributes(
        string $sourceAgentKey,
        string $snapshotName,
        string $rescueAgentKey
    ): CloneSpec {
        $targetDatasetName = self::AGENT_ZFS_BASE . "/$rescueAgentKey";
        $targetMountpoint = self::AGENT_ZFS_MOUNT_ROOT . "/$rescueAgentKey";
        return new CloneSpec(
            self::AGENT_ZFS_BASE,
            $sourceAgentKey,
            $snapshotName,
            $targetDatasetName,
            $targetMountpoint,
            RestoreType::RESCUE
        );
    }

    /**
     * Create a new clone spec from an Asset instance
     *
     * @param Asset $asset an asset instance
     * @param string $snapshotName snapshot to clone from
     * @param string $suffix clone type identifier, can be empty string
     * @param bool|null $sync Whether or not the clone should have synchronous writes enabled/disabled/inherited (default: inherited)
     * @return CloneSpec
     */
    public static function fromAsset(
        Asset $asset,
        string $snapshotName,
        string $suffix,
        bool $sync = null
    ): CloneSpec {
        $isShare = $asset->isType(AssetType::SHARE);
        $assetKey = $asset->getKeyName();
        return self::fromAssetAttributes($isShare, $assetKey, $snapshotName, $suffix, $sync);
    }

    /**
     * Create a new clone spec from attributes
     *
     * @param bool $isShare true if the asset is a Share
     * @param string $assetKey key name of source asset
     * @param string $snapshotName snapshot to clone from
     * @param string $suffix clone type identifier, can be empty string
     * @param bool $sync Whether or not to enable/disable/inherit sync writes for clone (default: inherit)
     * @return CloneSpec
     */
    public static function fromAssetAttributes(
        bool $isShare,
        string $assetKey,
        string $snapshotName,
        string $suffix,
        bool $sync = null
    ): CloneSpec {
        return $isShare
            ? self::fromShareAttributes($assetKey, $snapshotName, $suffix, $sync)
            : self::fromAgentAttributes($assetKey, $snapshotName, $suffix, $sync);
    }

    /**
     * Create a new clone spec for a share dataset
     *
     * @param string $assetKey
     * @param string $snapshotName
     * @param string $suffix
     * @param bool $sync
     * @return CloneSpec
     */
    public static function fromShareAttributes(
        string $assetKey,
        string $snapshotName,
        string $suffix,
        bool $sync = null
    ): CloneSpec {
        $cloneName = self::createCloneName($assetKey, $snapshotName, $suffix);
        $targetDatasetName = self::CLONE_ZFS_BASE . "/" . $cloneName;

        // shares are zvols, and do not have a zfs mountpoint
        return new CloneSpec(
            self::SHARE_ZFS_BASE,
            $assetKey,
            $snapshotName,
            $targetDatasetName,
            '',
            $suffix,
            $sync
        );
    }

    /**
     * Create a new clone spec for an agent dataset
     *
     * @param string $assetKey
     * @param string $snapshotName
     * @param string $suffix
     * @param bool $sync
     * @return CloneSpec
     */
    public static function fromAgentAttributes(
        string $assetKey,
        string $snapshotName,
        string $suffix,
        bool $sync = null
    ): CloneSpec {
        $cloneName = self::createCloneName($assetKey, $snapshotName, $suffix);
        $targetDatasetName = self::CLONE_ZFS_BASE . "/" . $cloneName;

        $targetMountPoint = "/$targetDatasetName";
        return new CloneSpec(
            self::AGENT_ZFS_BASE,
            $assetKey,
            $snapshotName,
            $targetDatasetName,
            $targetMountPoint,
            $suffix,
            $sync
        );
    }

    /**
     * Create a new clone spec from attributes of the zfs dataset
     *
     * @param string $datasetName the name of the zfs dataset
     * @param string $origin the name and snapshot the dataset was cloned from
     * @param string $mountPoint the mountpoint of the dataset
     * @return CloneSpec|null returns null if the clone information cannot be parsed
     */
    public static function fromZfsDatasetAttributes(string $datasetName, string $origin, string $mountPoint)
    {
        // homePool/home/agents/8b638681fae0421996ce7ca34c818843@1531410654
        $originRegex = '~(?<zfsbase>homePool/(home(/agents)?))/(?<assetkey>\S+)@(?<snapshot>\S+)~';
        if (!empty($origin) && preg_match($originRegex, $origin, $originMatches)) {
            // test for special case of rescue agent (clone dataset is under homePool/home/agents instead of homePool/)
            // ex: homePool/home/agents/Rescue-10.70.132.158-1, OR homePool/home/agents/4d13b9351f984d3eb8cb38c864a7f540
            if (preg_match('~homePool/home/agents/(?<clonename>\S+)~', $datasetName, $rescueMatches)) {
                return self::fromRescueAgentAttributes(
                    $originMatches['assetkey'],
                    $originMatches['snapshot'],
                    $rescueMatches['clonename']
                );
            }

            // its a regular asset clone, attempt to parse out suffix from dataset name
            // homePool/4d13b9351f984d3eb8cb38c864a7f540-1531152006-file
            $cloneNameRegex = '~homePool/(?!home|os|transfer|.recv)(?<clonename>\S+)~';
            if (preg_match($cloneNameRegex, $datasetName, $nameMatches)) {
                $haystack = $nameMatches['clonename'];
                $assetKey = $originMatches['assetkey'];

                // remove assetkey from beginning clonename so we can more easily identify the suffix
                // (assetkey may contain hyphens which would make suffix identification difficult, ex: lfm-ra-01)
                if (substr($haystack, 0, strlen($assetKey)) === $assetKey) {
                    $haystack = substr($haystack, strlen($assetKey));
                }

                // identify the suffix, ex: `-file` or `-differential-rollback` or `-bmr-00163e641991`
                if (preg_match('/-(?<suffix>([a-z]+)(-[a-z0-9]+)?)$/i', $haystack, $suffixMatches)) {
                    $suffix = $suffixMatches['suffix'];
                }

                // zfs reports no mountpoint as '-', change that to empty string
                $mountPoint =  ($mountPoint === '-') ? '' : $mountPoint;

                return new CloneSpec(
                    $originMatches['zfsbase'],
                    $assetKey,
                    $originMatches['snapshot'],
                    $datasetName,
                    $mountPoint,
                    $suffix ?? ''
                );
            }
        }

        return null;
    }

    /**
     * Derive the name of the clone from the given attributes
     *
     * @param string $assetKey
     * @param string $snapshotname
     * @param string $suffix
     * @return string
     */
    private static function createCloneName(string $assetKey, string $snapshotname, string $suffix)
    {
        // These values correspond to the types of operations we only want to do once per agent, "active" indicates virtualization
        $isPerAgentSuffix = in_array($suffix, Restore::$suffixesWithoutSnapshot);

        $parts[] = $assetKey;

        if (!$isPerAgentSuffix) {
            $parts[] = $snapshotname;
        }

        if (!empty($suffix)) {
            $parts[] = $suffix;
        }

        return implode('-', $parts);
    }

    /**
     * @return string key name of source asset
     * @example d13b9351f984d3eb8cb38c864a7f540
     */
    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    /**
     * @return string full zfs source dataset name
     * @example homePool/home/agents/4d13b9351f984d3eb8cb38c864a7f540
     */
    public function getSourceDatasetName(): string
    {
        return $this->sourceDatasetName;
    }

    /**
     * @return string full zfs clone name
     * @example  homePool/home/agents/4d13b9351f984d3eb8cb38c864a7f540@1531410654
     */
    public function getSourceCloneName(): string
    {
        return "{$this->getSourceDatasetName()}@{$this->getSnapshotName()}";
    }

    /**
     * @return string source zfs snapshot name
     * @example 1531410654
     */
    public function getSnapshotName(): string
    {
        return $this->snapshotName;
    }

    /**
     * @return string full zfs target dataset name
     * @example homePool/4d13b9351f984d3eb8cb38c864a7f540-1531410654-file
     */
    public function getTargetDatasetName(): string
    {
        return $this->targetDatasetName;
    }

    /**
     * @return string the zfs target data name without its full zfs path
     * @example 4d13b9351f984d3eb8cb38c864a7f540-1531410654-file
     */
    public function getTargetDatasetShortName(): string
    {
        $parts = explode('/', $this->targetDatasetName);
        return $parts[count($parts) - 1];
    }

    /**
     * @return string target filesystem mountpoint
     * @example /homePool/4d13b9351f984d3eb8cb38c864a7f540-1531410654-file
     */
    public function getTargetMountpoint(): string
    {
        return $this->targetMountpoint;
    }

    /**
     * @return string short identifier of the clone type
     * @example file
     */
    public function getSuffix(): string
    {
        return $this->suffix;
    }

    /**
     * @return bool|null whether or not sync writes should enabled/disabled/inherited for this clone
     *     true = enabled, false = disabled, null = inherit
     */
    public function getSync()
    {
        return $this->sync;
    }

    private static function assertNotEmptyString(string $name, $value)
    {
        if (strlen($value) === 0) {
            throw new InvalidArgumentException("Expected non empty $name");
        }
    }
}
