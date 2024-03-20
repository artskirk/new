<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Utility\Security\SecretString;

/**
 * API endpoint for managing BMR targets.
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
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class Bmr
{
    /** @var DifferentialRollbackService */
    private $differentialRollbackService;

    public function __construct(DifferentialRollbackService $differentialRollbackService)
    {
        $this->differentialRollbackService = $differentialRollbackService;
    }

    /**
     * Creates a MercuryFTP restore target.
     *
     * Returns same information as getTargetInfo().
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type = "agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "suffix" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^(differential-rollback|bmr)~")
     * })
     * @param string $assetKey The agent to create the target for
     * @param int $snapshot The snapshot to create the target for
     * @param string $suffix The restore suffix to use for the snapshot
     * @param string|null $passphrase Optional password for encrypted agents
     * @return array Some data about the restore
     */
    public function create(string $assetKey, int $snapshot, string $suffix, string $passphrase = null)
    {
        $passphrase = new SecretString($passphrase);
        if (!$this->differentialRollbackService->restoreExists($assetKey, $snapshot, $suffix)) {
            $this->differentialRollbackService->create($assetKey, $snapshot, $suffix, $passphrase);
        }

        return $this->differentialRollbackService->getRestoreData($assetKey, $snapshot, $suffix);
    }

    /**
     * Removes a MercuryFTP restore target.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type = "agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "suffix" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^(differential-rollback|bmr)~")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return true
     */
    public function remove(string $assetKey, int $snapshot, string $suffix)
    {
        $this->differentialRollbackService->remove($assetKey, $snapshot, $suffix);
        return true;
    }

    /**
     * Gets information about the MercuryFTP target for the restore.
     *
     * Example return:
     * {
     *   "target": "iqn.2007-01.net.datto.dev.temp.cosmiccow.1abb90f60c8c497eb4f2bc86ef218689-1548712808-differential-rollback",
     *   "password": "B8jWMUEId3Z19mNW2ydezosO2McvdJoz",
     *   "luns": [
     *     {
     *       "id": 0,
     *       "uuid": "4295fb7b-0000-0000-0000-100000000000",
     *       "path": "/homePool/1abb90f60c8c497eb4f2bc86ef218689-1548712808-differential-rollback/4295fb7b-0000-0000-0000-100000000000.datto",
     *       "blkid_uuid": "DC66F42566F3FE58"
     *     },
     *     {
     *       "id": 1,
     *       "uuid": "9dbfd364-0000-0000-0000-501f00000000",
     *       "path": "/homePool/1abb90f60c8c497eb4f2bc86ef218689-1548712808-differential-rollback/9dbfd364-0000-0000-0000-501f00000000.datto",
     *       "blkid_uuid": "AE963D01963CCC19"
     *     }
     *   ]
     * }
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type = "agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "suffix" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^(differential-rollback|bmr)~")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return array
     */
    public function getTargetInfo(string $assetKey, int $snapshot, string $suffix)
    {
        return $this->differentialRollbackService->getRestoreData($assetKey, $snapshot, $suffix);
    }

    /**
     * Destroy a BMR restore by agent and point
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type = "agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "restoreType" = @Symfony\Component\Validator\Constraints\Choice(choices = { "bmr", "differential-rollback" }),
     * })
     * @param string $assetKey the agent with the clone
     * @param int $snapshot the snapshot for the agent with the clone
     * @param string $restoreType
     * @return bool $result true on success, false on failure
     */
    public function removeAllForPoint(string $assetKey, int $snapshot, string $restoreType)
    {
        $this->differentialRollbackService->removeAllForPoint($assetKey, $snapshot, $restoreType);
        return true;
    }
}
