<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\Common\Resource\ProcessFactory;
use Datto\Iscsi\IscsiTargetNotFoundException;
use Datto\Iscsi\UserType;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\SanitizedException;
use Datto\Restore\Iscsi\IscsiMounterService;
use Datto\Asset\Agent\MountHelper;
use Datto\Iscsi\IscsiTarget;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Security\SecretString;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * API endpoint query and change agent settings
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Dakota Baber <dbaber@datto.com>
 */
class Iscsi implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var IscsiMounterService */
    private $iscsiMounter;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var MountHelper */
    private $mountHelper;

    private ProcessFactory $processFactory;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        IscsiMounterService $iscsiMounter,
        IscsiTarget $iscsiTarget,
        MountHelper $mountHelper,
        ProcessFactory $processFactory,
        Filesystem $filesystem
    ) {
        $this->iscsiMounter = $iscsiMounter;
        $this->iscsiTarget = $iscsiTarget;
        $this->mountHelper = $mountHelper;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Creates an iSCSI target
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z0-9\._-]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[-]{0,1}\d+$~")
     * })
     * @param string $agentName The agent to create the target for
     * @param string $snapshot The snapshot to create the target for
     * @param string|null $password Optional password for encrypted agents
     * @return string The target name (iqn)
     */
    public function createTarget(string $agentName, string $snapshot, $password = null): string
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info('VRE0001 Creating volume restore ...');

        try {
            $password = $password ? new SecretString($password) : null;
            $this->iscsiMounter->createClone($agentName, $snapshot, $password);
            $targetName = $this->iscsiMounter->createIscsiTarget($agentName, $snapshot);
            $this->iscsiMounter->addRestore($agentName, $snapshot, $targetName);
            $this->logger->info('VRE0002 Created volume restore.');

            return $targetName;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$password]);
        }
    }

    /**
     * Creates an iSCSI target with chap authentication
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKeyName" = @Datto\App\Security\Constraints\AssetExists(),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[-]{0,1}\d+$~"),
     *   "blockSize" = @Symfony\Component\Validator\Constraints\Choice(choices = { 512, 4096 }),
     *   "chapEnabled" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "chapPassword" = @Symfony\Component\Validator\Constraints\AtLeastOneOf(
     *       @Symfony\Component\Validator\Constraints\Blank(),
     *       @Symfony\Component\Validator\Constraints\Length(min=12, max=16)
     *   ),
     *   "mutualChapEnabled" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "mutualChapPassword" = @Symfony\Component\Validator\Constraints\AtLeastOneOf(
     *       @Symfony\Component\Validator\Constraints\Blank(),
     *       @Symfony\Component\Validator\Constraints\Length(min=12, max=16)
     *   )
     * })
     * @param string $assetKeyName The asset to create the target for
     * @param string $snapshot The snapshot to create the target for
     * @param int $blockSize The block size to use for the target
     * @param bool $chapEnabled True if CHAP is enabled, False otherwise
     * @param string $chapUsername The CHAP username
     * @param string $chapPassword The CHAP password
     * @param bool $mutualChapEnabled True if Mutual CHAP is enabled, False otherwise
     * @param string $mutualChapUsername The Mutual CHAP username
     * @param string $mutualChapPassword The Mutual CHAP password
     *
     * @return string The target name (iqn)
     */
    public function createTargetWithChap(
        string $assetKeyName,
        string $snapshot,
        int $blockSize = IscsiShare::DEFAULT_BLOCK_SIZE,
        bool $chapEnabled = false,
        string $chapUsername = '',
        string $chapPassword = '',
        bool $mutualChapEnabled = false,
        string $mutualChapUsername = '',
        string $mutualChapPassword = '',
        string $agentPassword = null
    ): string {
        $this->logger->setAssetContext($assetKeyName);
        $this->logger->info('VRE0003 Creating volume restore with CHAP authentication ...');

        try {
            $agentPassword = $agentPassword ? new SecretString($agentPassword) : null;
            $this->iscsiMounter->createClone($assetKeyName, $snapshot, $agentPassword);
            $targetName = $this->iscsiMounter->createIscsiTarget(
                $assetKeyName,
                $snapshot,
                false,
                null,
                $blockSize
            );
            $this->iscsiMounter->addRestore($assetKeyName, $snapshot, $targetName);

            if ($chapEnabled) {
                $this->iscsiTarget->addTargetChapUser(
                    $targetName,
                    UserType::INCOMING(),
                    $chapUsername,
                    $chapPassword,
                    false
                );
                if ($mutualChapEnabled) {
                    $this->iscsiTarget->addTargetChapUser(
                        $targetName,
                        UserType::OUTGOING(),
                        $mutualChapUsername,
                        $mutualChapPassword,
                        false
                    );
                }
            }
            $this->iscsiTarget->writeChanges();
            $this->logger->info('VRE0004 Created volume restore with CHAP authentication ...');

            return $targetName;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$agentPassword]);
        }
    }

    /**
     * Destroys an iSCSI target
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z0-9\._-]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[-]{0,1}\d+$~")
     * })
     * @param string $agentName The asset to destroy the iSCSI target for
     * @param string $snapshot The snapshot to destroy the iSCSI target for
     * @return bool True if the target was destroyed
     */
    public function destroyTarget(string $agentName, string $snapshot): bool
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info('VRE0006 Deleting volume restore ...'); // log code is used by device-web see DWI-2252
        $this->iscsiMounter->destroyIscsiTarget($agentName, $snapshot);
        $this->iscsiMounter->destroyClone($agentName, $snapshot);
        $this->iscsiMounter->removeRestore($agentName, $snapshot);
        $this->logger->info('VRE0007 Deleted volume restore.');

        return true;
    }

    /**
     * List the volume guid for each lun in an iSCSI target.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[-]{0,1}\d+$~")
     * })
     * @param string $agentName
     * @param string $snapshot
     * @return string[] lun => the guid for the volume the lun corresponds to
     */
    public function getVolumeGuids(string $agentName, string $snapshot): array
    {
        $targetName = $this->iscsiMounter->makeTargetName($agentName, $snapshot);
        return $this->iscsiTarget->listTargetVolumeGuids($targetName);
    }

    /**
     * Return a list of all the files with their change time for an agent's volume.
     * This operates on an existing iscsi restore.
     * This endpoint is used by rapid rollback in datto-stick.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="windows"),
     *     "snapshot" = @Symfony\Component\Validator\Constraints\NotBlank(),
     *     "mountpoint" = @Symfony\Component\Validator\constraints\NotBlank()
     * })
     * @param string $agentKey
     * @param int $snapshot
     * @param string $mountpoint Windows drive letter. Ex: 'C'
     * @return string[] 'files' => base64 encoded then gzip compressed string of files
     */
    public function find(string $agentKey, int $snapshot, string $mountpoint)
    {
        try {
            $sourceDir = "/homePool/$agentKey-$snapshot-iscsimounter";
            $destDir = $sourceDir . '/fileMount';

            $targetName = $this->iscsiMounter->makeTargetName($agentKey, $snapshot);
            if (!$this->iscsiTarget->doesTargetExist($targetName)) {
                throw new IscsiTargetNotFoundException('No iSCSI targets found');
            }

            $this->mountHelper->mountTree($agentKey, $sourceDir, $destDir);

            $path = $destDir . '/' . $mountpoint;

            // process builder's setWorkingDirectory() falls back to using '/datto/web' if the path doesn't exist
            if (!$this->filesystem->exists($path)) {
                throw new Exception("Failed to mount file tree. Attempted to find files in path: '$path' but it does not exist.");
            }

            // file path, ctime, mtime, size (bytes)
            $process = $this->processFactory
                ->getFromShellCommandLine('find . -printf \'%p\t%C@\t%T@\t%s\n\' | sort | gzip - | base64 -')
                ->setWorkingDirectory($path)
                ->setTimeout(null);

            $process->mustRun();

            $base64CompressedFiles = $process->getOutput();
            return ['files' => $base64CompressedFiles];
        } finally {
            $this->mountHelper->unmountTree($destDir);
        }
    }
}
