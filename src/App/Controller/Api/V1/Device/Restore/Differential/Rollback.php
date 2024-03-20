<?php

namespace Datto\App\Controller\Api\V1\Device\Restore\Differential;

use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Restore\RestoreType;
use Datto\Utility\Security\SecretString;

/**
 * API endpoint for managing differential rollback (DTC Rapid Rollback) targets.
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
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 * @codeCoverageIgnore
 */
class Rollback
{
    /** @var DifferentialRollbackService */
    private $differentialRollbackService;

    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    public function __construct(
        DifferentialRollbackService $differentialRollbackService,
        AgentSnapshotService $agentSnapshotService
    ) {
        $this->differentialRollbackService = $differentialRollbackService;
        $this->agentSnapshotService = $agentSnapshotService;
    }

    /**
     * Creates a MercuryFTP differential restore target.
     *
     * Returns same information as getTargetInfo().
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_DIFFERENTIAL_ROLLBACK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type = "agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric")
     * })
     * @param string $assetKey The agent to create the target for
     * @param int $snapshot The snapshot to create the target for
     * @param string|null $passphrase Optional password for encrypted agents
     * @return array Some data about the restore
     */
    public function create(string $assetKey, int $snapshot, string $passphrase = null)
    {
        $suffix = RestoreType::DIFFERENTIAL_ROLLBACK;
        $passphrase = $passphrase ? new SecretString($passphrase) : null;
        $this->differentialRollbackService->create($assetKey, $snapshot, $suffix, $passphrase);

        return $this->differentialRollbackService->getRestoreData($assetKey, $snapshot, $suffix);
    }

    /**
     * Removes a MercuryFTP differential restore target.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_DIFFERENTIAL_ROLLBACK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @return true
     */
    public function remove(string $assetKey, int $snapshot)
    {
        $suffix = RestoreType::DIFFERENTIAL_ROLLBACK;
        $this->differentialRollbackService->remove($assetKey, $snapshot, $suffix);
        return true;
    }

    /**
     * Gets information about the MercuryFTP target for the differential rollback.
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
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_DIFFERENTIAL_ROLLBACK_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @return array
     */
    public function getTargetInfo(string $assetKey, int $snapshot)
    {
        $suffix = RestoreType::DIFFERENTIAL_ROLLBACK;
        return $this->differentialRollbackService->getRestoreData($assetKey, $snapshot, $suffix);
    }

    /**
     * @deprecated This is necessary for DTC Differential Restores and shouldn't be used for anything else.
     *
     * Get agentInfo and voltab information for the restore.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     *
     * @param string $assetKey
     * @param int $snapshot
     * @return array
     */
    public function getAgentInfo(string $assetKey, int $snapshot)
    {
        $snapshot = $this->agentSnapshotService->get($assetKey, $snapshot);

        $agentInfo = $snapshot->getKey($assetKey . '.agentInfo');
        $voltab = $snapshot->getKey('voltab');

        /**
         * TODO: probably should fix the actual problem and come up with a way to retroactively
         * update the agentInfo files in snapshots but for now this is the least risky and quicker fix.
         *
         * The agentInfoBuilder is missing hiddenSectors/label fields for volumes which can cause DTC
         * agents to be missing those fields in snapshots. BMR expects these so it will fail.
         * This fix will either use the existing value in the agentInfo or default to empty so the BMR
         * can proceed without issue. Doing this fix is safer than trying to store the fields in the
         * agentInfo file consistency wise.
         */
        $agentInfoArray = unserialize($agentInfo, ['allowed_classes' => ['array']]);
        foreach ($agentInfoArray['volumes'] as $key => $volume) {
            $volume['hiddenSectors'] = $volume['hiddenSectors'] ?? 0;
            $volume['label'] = $volume['label'] ?? '';
            $agentInfoArray['volumes'][$key] = $volume;
        }
        foreach ($agentInfoArray['Volumes'] as $key => $volume) {
            $volume['hiddenSectors'] = $volume['hiddenSectors'] ?? 0;
            $volume['label'] = $volume['label'] ?? '';
            $agentInfoArray['Volumes'][$key] = $volume;
        }

        return [
            'agentUuid' => $assetKey,
            'agentInfo' => $agentInfoArray,
            'voltab' => json_decode($voltab, true)
        ];
    }
}
