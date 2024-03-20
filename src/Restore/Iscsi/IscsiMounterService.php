<?php
namespace Datto\Restore\Iscsi;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Config\LocalConfig;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Dataset\ZVolDataset;
use Datto\Iscsi\IscsiTarget;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Security\SecretString;
use Exception;
use InvalidArgumentException;

/**
 * Class IscsiMounterService
 *
 * @author Dakota Baber <dbaber@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class IscsiMounterService
{
    const MODE_EXPORT = 'export';
    const MODE_VIRTUALIZATION = 'virtualization';
    const SUPPORTED_MODES_ARRAY = [self::MODE_EXPORT, self::MODE_VIRTUALIZATION];

    const SUFFIX_RESTORE = 'iscsi';
    const SUFFIX_EXPORT = 'iscsimounter';
    const SUFFIX_VIRTUALIZATION = 'active';

    /**
     * The start offset of the GPT header.
     */
    const GPT_HEADER_START_OFFSET = 512;

    /**
     * The offset of the header size into the GPT header.
     */
    const GPT_HEADER_SIZE_OFFSET = 0x0c;

    /**
     * The offset of the header CRC into the GPT header.
     */
    const GPT_CRC_OFFSET = 0x10;

    /**
     * The offset of the GUID into the GPT header.
     */
    const GPT_GUID_OFFSET = 0x38;

    /**
     * The offset off the disk signature into the MBR header.
     */
    const MBR_DISK_SIGNATURE_OFFSET = 0x1b8;

    /**
     * Default prefix for iSCSI targets this class creates
     */
    const TARGET_PREFIX = 'iscsimounter-';

    private string $configKey = 'iscsimounter';
    private Filesystem $filesystem;
    private EncryptionService $encryptionService;
    private LocalConfig $config;
    private IscsiTarget $iscsiTarget;
    private string $mode;
    private string $suffix = self::SUFFIX_VIRTUALIZATION;
    private bool $isCustomSuffix = false;
    private RestoreService $restoreService;
    private AssetService $assetService;
    private DateTimeService $dateTimeService;
    private AssetCloneManager $cloneManager;
    private Collector $collector;
    private TempAccessService $tempAccessService;
    private VirtualDisksFactory $virtualDisksFactory;

    public function __construct(
        Filesystem $filesystem,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        LocalConfig $config,
        IscsiTarget $iscsiTarget,
        AssetCloneManager $cloneManager,
        RestoreService $restoreService,
        AssetService $assetService,
        DateTimeService $dateTimeService,
        Collector $collector,
        VirtualDisksFactory $virtualDisksFactory,
        string $mode = self::MODE_EXPORT
    ) {
        $this->filesystem = $filesystem;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->config = $config;
        $this->iscsiTarget = $iscsiTarget;
        $this->mode = $mode;
        $this->cloneManager = $cloneManager;
        $this->restoreService = $restoreService;
        $this->assetService = $assetService;
        $this->dateTimeService = $dateTimeService;
        $this->collector = $collector;
        $this->virtualDisksFactory = $virtualDisksFactory;
    }

    /**
     * Clones specified asset and snapshot
     *
     * @param string $assetKeyName The name of the asset to create the target for
     * @param string $snapshot The name of the snapshot to create the target for
     * @param SecretString|null $password Optional password for encrypted agents
     */
    public function createClone(
        $assetKeyName,
        $snapshot,
        SecretString $password = null
    ): void {
        $asset = $this->assetService->get($assetKeyName);
        $isShare = $asset->isType(AssetType::SHARE);

        if (!$isShare) {
            /** @var Agent $asset */
            $passphraseIsRequired = $asset->getEncryption()->isEnabled()
                && !$this->tempAccessService->isCryptTempAccessEnabled($assetKeyName);
            if ($passphraseIsRequired && $password !== null) {
                // Will throw exception if incorrect password
                $this->encryptionService->decryptAgentKey($assetKeyName, $password);
            }
        }

        if (!in_array($this->mode, self::SUPPORTED_MODES_ARRAY)) {
            throw new InvalidArgumentException('Unknown cloning mode');
        }

        $suffix = $this->determineSuffix($asset);

        $cloneSpec = CloneSpec::fromAssetAttributes($isShare, $assetKeyName, $snapshot, $suffix);
        $this->cloneManager->createClone($cloneSpec);
    }

    /**
     * Creates an iSCSI target for a specified asset and snapshot
     *
     * @param string $assetKeyName The name of the asset to create the target for
     * @param string $snapshot The name of the snapshot to create the target for
     * @param string $suffix
     * @param bool $isTemporary
     * @param VirtualDisks|null $disks an array of virtual disks to attach,
     *  if not provided the code will use agentInfo['volumes']
     * @param string $blockSize
     * @param string $signature
     *
     * @return string The name of the iSCSI target
     */
    public function createIscsiTarget(
        string $assetKeyName,
        string $snapshot,
        bool $isTemporary = false,
        VirtualDisks $disks = null,
        string $blockSize = '512',
        string $signature = null
    ): string {
        $asset = $this->assetService->get($assetKeyName);
        $this->collector->increment(Metrics::RESTORE_STARTED, [
            'type' => Metrics::RESTORE_TYPE_VOLUME_ISCSI,
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);

        $isShare = $asset->isType(AssetType::SHARE);
        $suffix = $this->determineSuffix($asset);
        $snapDir = $this->determineSnapDir($assetKeyName, $snapshot, $suffix);
        $targetName = $this->makeTargetName($assetKeyName, $snapshot, $isTemporary);

        if (in_array($targetName, $this->iscsiTarget->listTargets())) {
            $this->iscsiTarget->deleteTarget($targetName);
            $this->iscsiTarget->writeChanges();
        }

        $this->iscsiTarget->createTarget($targetName);
        $this->addTargetToConfig($targetName, $assetKeyName, $snapshot, $suffix);

        if ($isShare) {
            $path = ZVolDataset::BLK_BASE_DIR . $snapDir;

            if (!$asset->getOriginDevice()->isReplicated()) {
                // if the share is replicated, the user will select the block size in the UI
                // set block size here if share is not replicated

                if ($asset->isType(AssetType::EXTERNAL_NAS_SHARE)) {
                    $blockSize = ExternalNasShare::DEFAULT_BLOCK_SIZE;
                } elseif ($asset instanceof IscsiShare) {
                    $blockSize = $asset->getBlockSize();
                }
            }
            $this->iscsiTarget->addLun(
                $targetName,
                $path,
                false,
                false,
                null,
                ["block_size=$blockSize"]
            );
        } else {
            if ($disks === null) {
                // Retrieve the volumes from the given asset's snapshot directory agentInfo
                $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, $suffix);
                $disks = $this->virtualDisksFactory->getVirtualDisks($cloneSpec);
            }

            $ioMode = $this->mode === self::MODE_VIRTUALIZATION ? 'wt' : 'ro';
            foreach ($disks as $disk) {
                // don't attach special boot disk for non virt purposes.
                if ($this->mode === self::MODE_EXPORT
                    && $disk->getRawFileName() === 'boot.datto') {
                    continue;
                }

                $dattoFile = sprintf('%s/%s', $snapDir, $disk->getRawFileName());

                if ($this->mode !== self::MODE_VIRTUALIZATION) {
                    $this->resolveSignatureCollisions($dattoFile, $disk->isGpt(), $signature);
                }

                $this->iscsiTarget->addLun(
                    $targetName,
                    $dattoFile,
                    $ioMode === 'ro',
                    $ioMode !== 'wt',
                    null,
                    ["block_size=$blockSize"]
                );
            }
        }

        $this->iscsiTarget->writeChanges();

        return $targetName;
    }

    /**
     * Makes an iSCSI target name.
     *
     * @param string $assetKeyName The name of the asset
     * @param string $snapshot The name of the snapshot
     * @param bool $isTemporary If true, the target won't be saved and restored on boot, false by default. See CP-5119.
     *
     * @return string iSCSI target name
     */
    public function makeTargetName(
        string $assetKeyName,
        string $snapshot,
        bool $isTemporary = false
    ): string {
        $prefix = self::TARGET_PREFIX;
        $suffix = '';

        $asset = $this->assetService->get($assetKeyName);
        if ($asset->isType(AssetType::SHARE)) {
            $prefix = '';
            $suffix = '-' . self::SUFFIX_RESTORE;
        }

        $namePartial = sprintf('%s-%s%s', $assetKeyName, $snapshot, $suffix);

        $targetName = $isTemporary
            ? $this->iscsiTarget->makeTargetNameTemp($namePartial, $prefix)
            : $this->iscsiTarget->makeTargetName($namePartial, $prefix);

        return $targetName;
    }

    /**
     * Destroys an iSCSI target defined by the agent name and snapshot time.
     *
     * @param string $agent The name of the agent
     * @param string $snapshot The name of the snapshot
     * @param bool $isTemporary
     */
    public function destroyIscsiTarget(
        string $agent,
        string $snapshot,
        bool $isTemporary = false
    ): void {
        $targetName = $this->makeTargetName($agent, $snapshot, $isTemporary);

        if ($this->iscsiTarget->doesTargetExist($targetName)) {
            $this->iscsiTarget->deleteTarget($targetName);
            $this->iscsiTarget->writeChanges();
        }

        $this->removeTargetFromConfig($targetName);
    }

    /**
     * Destroys a clone defined by the asset name and snapshot time.
     *
     * @param string $assetKeyName The name of the asset
     * @param string $snapshot The name of the snapshot
     */
    public function destroyClone(string $assetKeyName, string $snapshot): void
    {
        $asset = $this->assetService->get($assetKeyName);

        $target = $this->findTarget($assetKeyName, $snapshot);
        $suffix = $target['suffix'] ?? $this->determineSuffix($asset);

        $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, $suffix);
        $this->cloneManager->destroyClone($cloneSpec);
    }

    /**
     * Gets the name and lastSession for each target in the config.
     */
    public function getAllTargets(): array
    {
        return json_decode($this->config->get($this->configKey), true) ?: [];
    }

    /**
     * Gets info for all the targets in the config.
     *
     * @return array A list containing the info for each of the targets in the config
     */
    public function getAllTargetsInfo(): array
    {
        $out = [];

        $iscsiMounterTargets = $this->getAllTargets();
        $time = $this->dateTimeService->getTime();

        foreach ($iscsiMounterTargets as $name => $info) {
            if (!$this->iscsiTarget->doesTargetExist($name)) {
                continue;
            }

            $target = ['name' => $name];
            $target['lastSession'] = $info['lastSession'];
            $target['timeSinceLastSession'] = $time - $info['lastSession'];
            $out[] = $target;
        }

        return $out;
    }

    /**
     * Set the mode of operation.
     *
     * This determines how this class opearates internally when executing
     * public methods.
     *
     * @param string $mode either MODE_EXPORT or MODE_VIRUTALIZATION
     */
    public function setMode(string $mode): void
    {
        if (in_array($mode, self::SUPPORTED_MODES_ARRAY)) {
            $this->mode = $mode;
        } else {
            throw new Exception(sprintf(
                'Unsupported IScsiMounter mode: %s',
                $mode
            ));
        }
    }

    /**
     * Allows to set custom suffix
     *
     * @param string $suffix
     */
    public function setSuffix(string $suffix): void
    {
        $this->suffix = $suffix;
        $this->isCustomSuffix = true;
    }

    /**
     * Add an ISCSI target to the restore UI.
     *
     * @param string $assetKeyName The name of the asset
     * @param string $snapshot The name of the snapshot of the asset
     * @param string $targetName Name of the ISCSI target
     */
    public function addRestore(
        string $assetKeyName,
        string $snapshot,
        string $targetName
    ): void {
        $asset = $this->assetService->get($assetKeyName);
        $time = $this->dateTimeService->getTime();
        $options = ['iscsiTarget' => $targetName];

        $restore = $this->restoreService->create(
            $assetKeyName,
            $snapshot,
            $this->determineSuffix($asset),
            $time,
            $options
        );

        $this->restoreService->getAll();
        $this->restoreService->add($restore);
        $this->restoreService->save();
    }

    /**
     * Remove an ISCSI target from the restore UI.
     *
     * @param string $assetKeyName The name of the asset
     * @param string $snapshot The name of the snapshot of the asset
     */
    public function removeRestore(string $assetKeyName, string $snapshot): void
    {
        $asset = $this->assetService->get($assetKeyName);
        $restore = $this->restoreService->find($assetKeyName, $snapshot, $this->determineSuffix($asset));

        if (isset($restore)) {
            $this->restoreService->getAll();
            $this->restoreService->remove($restore);
            $this->restoreService->save();
        }
    }

    /**
     * Packs an integer to be stored as binary
     *
     * @param int $in The integer to pack
     * @param int $padToBits The padded length of the int
     * @param bool $littleEndian Whether or not the integer is little endian
     * @return string The packed integer
     */
    private function packInt(int $in, int $padToBits = 64, bool $littleEndian = true): string
    {
        if (is_int($in) === false) {
            $in = 0;
        }

        $in = decbin($in);
        $in = str_pad($in, $padToBits, '0', STR_PAD_LEFT);
        $out = '';

        for ($i = 0, $len = strlen($in); $i < $len; $i += 8) {
            $out .= chr(bindec(substr($in, $i, 8)));
        }

        if ($littleEndian) {
            $out = strrev($out);
        }

        return $out;
    }

    /**
     * Attempts to resolve drive signature collisions by setting a random drive signature for GPT or MBR accordingly.
     *
     * @param string $dattoFile The datto file to modify
     * @param bool $isGpt
     * @param string $signature
     */
    private function resolveSignatureCollisions(
        string $dattoFile,
        bool $isGpt,
        ?string $signature = null
    ): void {
        $fp = $this->filesystem->open($dattoFile, 'rb+');

        // We need to check if it's GPT or MBR and change the drive signature accordingly in order to prevent
        // drive signature collisions when mounting on windows

        // GPT header should start at LBA 1
        $efiStart = self::GPT_HEADER_START_OFFSET;

        if ($isGpt) {
            // Get the EFI header size
            $headerSizeBytes = $this->filesystem->readAt($fp, 4, $efiStart + self::GPT_HEADER_SIZE_OFFSET);
            $headerSize = unpack('i', $headerSizeBytes);
            $headerSize = $headerSize[1];

            // Zero the CRC
            $this->filesystem->writeAt($fp, "\x00\x00\x00\x00", 4, $efiStart + self::GPT_CRC_OFFSET);

            // Set a random GUID
            $signature = $signature ?: random_bytes(16);
            $this->filesystem->writeAt($fp, $signature, 16, $efiStart + self::GPT_GUID_OFFSET);

            // Calculate the new header hash
            $header = $this->filesystem->readAt($fp, $headerSize, $efiStart);
            $crc = $this->packInt(crc32($header));
            // Write new hash
            $this->filesystem->writeAt($fp, $crc, 4, $efiStart + self::GPT_CRC_OFFSET);
        } else {
            // If its MBR, set a random disk signature
            $signature = $signature ?: random_bytes(4);
            $this->filesystem->writeAt($fp, $signature, 4, self::MBR_DISK_SIGNATURE_OFFSET);
        }

        $this->filesystem->close($fp);
    }

    /**
     * Add a specific target to the config.
     *
     * @param string $targetName The name of the target to add to the config
     * @param string $agent
     * @param int $snap
     * @param string $suffix
     */
    private function addTargetToConfig(string $targetName, string $agent, int $snap, string $suffix): void
    {
        $targets = $this->getAllTargets();

        $targets[$targetName]['lastSession'] = $this->dateTimeService->getTime();
        $targets[$targetName]['agent'] = $agent;
        $targets[$targetName]['snapshot'] = $snap;
        $targets[$targetName]['suffix'] = $suffix;

        $this->config->set($this->configKey, json_encode($targets));
    }

    /**
     * Helper function to determine snap clone directory.
     *
     * @param string $agent
     * @param string $snapshot
     * @param string $suffix
     *
     * @return string
     */
    private function determineSnapDir(string $agent, string $snapshot, string $suffix): string
    {
        if ($suffix === RestoreType::RESCUE) {
            return '/home/agents/' . $agent;
        }
        $partial = ($this->mode === self::MODE_EXPORT) ? $snapshot . '-' . $suffix : $suffix;
        return '/homePool/' . $agent . '-' . $partial;
    }

    /**
     * Helper function to determine clone suffix.
     *
     * @param Asset $asset
     *
     * @return string
     */
    private function determineSuffix(Asset $asset): string
    {
        // if the suffix was set explicitly via setSuffix, do not auto-detect
        if (!$this->isCustomSuffix) {
            if ($asset->isType(AssetType::SHARE)) {
                $this->suffix = self::SUFFIX_RESTORE;
            } elseif ($this->mode === self::MODE_VIRTUALIZATION) {
                $this->suffix = self::SUFFIX_VIRTUALIZATION;
            } else {
                $this->suffix = self::SUFFIX_EXPORT;
            }
        }

        return $this->suffix;
    }

    /**
     * Finds the info target based on agent name and snapshot time.
     *
     * @param string $agent
     * @param string
     * @return array|null
     */
    private function findTarget(string $agent, string $snapshot)
    {
        $targetName = $this->makeTargetName($agent, $snapshot);

        $target = null;
        $iscsiMounterTargets = $this->getAllTargets();

        if (isset($iscsiMounterTargets[$targetName])) {
            $target = $iscsiMounterTargets[$targetName];
        }

        return $target;
    }

    /**
     * Removes a specific target from the config.
     *
     * @param string $targetName The name of the target to remove
     */
    private function removeTargetFromConfig(string $targetName): void
    {
        $targets = $this->getAllTargets();

        if ($targets && isset($targets[$targetName])) {
            unset($targets[$targetName]);
            $this->config->set($this->configKey, json_encode($targets));
        }
    }
}
