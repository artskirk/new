<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;
use Datto\Asset\VerificationScript;
use Datto\Verification\VerificationService;

/**
 * API endpoint for snapshot functions
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 */
class Verification extends AbstractAssetEndpoint
{
    /** @var VerificationService */
    private $verificationService;

    public function __construct(
        AssetService $assetService,
        VerificationService $verificationService
    ) {
        parent::__construct($assetService);

        $this->verificationService = $verificationService;
    }

    /**
     * Queue up an asset for verifications.
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return bool
     */
    public function queue(string $assetKey, int $snapshotEpoch): bool
    {
        $this->logger->info('VER4000 Called v1/device/asset/verification/queue', ['agent' => $assetKey, 'snapshot' => $snapshotEpoch]);

        $asset = $this->assetService->get($assetKey);

        $this->verificationService->queue($asset, $snapshotEpoch);

        return true;
    }

    /**
     * Deqeue an asset from verifications.
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_DELETE")
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return bool
     */
    public function remove(string $assetKey, int $snapshotEpoch): bool
    {
        $this->logger->info('VER4001 Called v1/device/asset/verification/remove', ['agent' => $assetKey, 'snapshot' => $snapshotEpoch]);

        $asset = $this->assetService->get($assetKey);

        $this->verificationService->remove($asset, $snapshotEpoch);

        return true;
    }

    /**
     * Clear the verification queue.
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_DELETE")
     * @return bool
     */
    public function removeAll(): bool
    {
        $this->logger->info('VER4002 Called v1/device/asset/verification/removeAll');

        $this->verificationService->removeAll();

        return true;
    }

    /**
     * Immediately run verifications in the background for a given assets snapshots.
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @param string $assetKey
     * @param int $point
     * @return bool
     */
    public function run(string $assetKey, int $point): bool
    {
        $this->logger->info('VER4003 Called v1/device/asset/verification/run', ['agent' => $assetKey, 'snapshot' => $point]);

        $asset = $this->assetService->get($assetKey);

        $this->verificationService->runInBackground($asset, $point);

        return true;
    }

    /**
     * Stop and cleanup verification process for a given asset.
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @param string $assetKey
     * @return bool
     */
    public function stop(string $assetKey): bool
    {
        $this->logger->info('VER4004 Called v1/device/asset/verification/stop', ['agent' => $assetKey]);

        $asset = $this->assetService->get($assetKey);

        $this->verificationService->cancel($asset);

        return true;
    }

    /**
     * Changes the execution order for the scripts
     * Scripts must be passed in as
     * [
     *  ['id'] => scriptName],
     *  ['id2'] => scriptName2],
     *  ...
     * ]
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @param string $assetName
     * @param string[] $scriptsJson
     * @return bool
     */
    public function updateScriptExecutionOrder($assetName, $scriptsJson): bool
    {
        $asset = $this->assetService->get($assetName);
        $scripts = json_decode($scriptsJson);
        $scriptArray = [];
        foreach ($scripts as $id => $name) {
            $scriptArray[] = new VerificationScript($id, $name, null);
        }
        $asset->getScriptSettings()->updateScriptExecutionOrder($scriptArray);
        $this->assetService->save($asset);

        return true;
    }

    /**
     * Returns all scripts associated with the asset, in order and of the format
     * {
     *  {
     *   "id":"myId",
     *   "name":"myName",
     *   "tier":"myTier"
     *   },
     *  ...
     * };
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_READ")
     * @param string $assetName
     * @return array
     */
    public function getScripts($assetName): array
    {
        $scriptArray = [];
        $agent = $this->assetService->get($assetName);
        $scripts = $agent->getScriptSettings()->getScripts();
        foreach ($scripts as $script) {
            $scriptArray[] = [
                "id" => $script->getId(),
                "name" => $script->getName(),
                "tier" => $script->getTier()
            ];
        }

        return $scriptArray;
    }

    /**
     * Deletes a single script
     * Scripts must be passed in as {"id":scriptID,"name":scriptName,"tier":tier}
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_DELETE")
     * @param string $assetName
     * @param string[] $script
     * @return bool
     */
    public function deleteScript($assetName, $script): bool
    {
        $asset = $this->assetService->get($assetName);
        $scriptObject = new VerificationScript($script['name'], $script['id'], $script['tier']);
        $asset->getScriptSettings()->deleteScript($scriptObject);
        $this->assetService->save($asset);

        return true;
    }

    /**
     * Deletes all scripts for the asset
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_DELETE")
     * @param string $assetName
     * @return bool
     */
    public function deleteAllScripts($assetName): bool
    {
        $asset = $this->assetService->get($assetName);
        $asset->getScriptSettings()->deleteAllScripts();

        return true;
    }

    /**
     * Gets the script output for a recovery point
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_READ")
     * @param string $assetName
     * @param string $epoch
     * @return array
     */
    public function getScriptsResults($assetName, $epoch): array
    {
        $asset = $this->assetService->get($assetName);
        $recoveryPoints = $asset->getLocal()->getRecoveryPoints();
        if (!$recoveryPoints->exists($epoch)) {
            return [];
        }
        $scriptResults = $recoveryPoints->get($epoch)->getVerificationScriptsResults()->getOutput();
        $scriptOutput = [];
        foreach ($scriptResults as $result) {
            $name = $this->getFilenameNoUniqId($result->getScriptName());
            if ($name == null) {
                $this->logger->setAssetContext($assetName);
                $this->logger->warning('SVR0005 Unable to get script filename', ['scriptName' => $result->getScriptName()]);
                continue;
            }
            $scriptOutput[] = [
                'scriptName' => $name,
                'scriptState' => $result->getState(),
                'scriptOutput' => $result->getOutput(),
                'scriptExitCode' => $result->getExitCode()
            ];
        }

        return $scriptOutput;
    }

    /**
     * Returns whether or not a recovery point has a failed script
     *
     * FIXME This endpoint should be moved to v1/device/asset/agent/verification
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_READ")
     * @param string $assetName
     * @return bool
     */
    public function getRecoveryPointScriptFailure($assetName): bool
    {
        $asset = $this->assetService->get($assetName);
        $assetRecoveryPoints = $asset->getLocal()->getRecoveryPoints()->getAll();
        $scriptFailure = false;
        foreach ($assetRecoveryPoints as $assetRecoveryPoint) {
            $verificationScriptsResults = ($assetRecoveryPoint) ?
                $assetRecoveryPoint->getVerificationScriptsResults() :
                null;
            if ($verificationScriptsResults) {
                foreach ($verificationScriptsResults->getOutput() as $output) {
                    if ($output->getExitCode() != 0 || !$verificationScriptsResults->getComplete()) {
                        $scriptFailure = true;
                        return $scriptFailure;
                    }
                }
            }
        }

        return $scriptFailure;
    }

    /**
     * Since script results are stored with script name as 01_abc123_my_script.extension
     * remove 01_abc123_ from the name.
     *
     * @param $scriptName
     * @return string|null
     */
    private function getFilenameNoUniqId($scriptName)
    {
        $strippedName = preg_replace('/^[0-9]*_[A-Za-z0-9]*_/', '', $scriptName);
        $strippedNameIsBaseName = $strippedName == $scriptName;
        $strippedNameInBaseName = strpos($scriptName, $strippedName) !== false;
        if ($strippedNameInBaseName || $strippedNameIsBaseName) {
            return $strippedName;
        } else {
            return null;
        }
    }
}
