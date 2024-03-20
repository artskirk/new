<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\Agent\Encryption\AbstractPassphraseException;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Log\SanitizedException;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Utility\Security\SecretString;
use Exception;

/**
 * Endpoint to Agent security routines.
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
 * @author John Fury Christ <jchrist@datto.com>
 */
class Security extends AbstractAgentEndpoint
{
    const SECURITY_ERROR_CODE_RESTORES_EXIST_FOR_AGENT = 100;
    const SECURITY_ERROR_MSG_RESTORES_EXIST_FOR_AGENT = 'Unable to set authorized user on agent(s) with active restores';

    private EncryptionService $encryptionService;
    private TempAccessService $tempAccessService;
    private CloudEncryptionService $cloudEncryptionService;
    private RestoreService $restoreService;

    public function __construct(
        AgentService $agentService,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        CloudEncryptionService $cloudEncryptionService,
        RestoreService $restoreService
    ) {
        parent::__construct($agentService);

        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->cloudEncryptionService = $cloudEncryptionService;
        $this->restoreService = $restoreService;
    }

    /**
     * Provide/remove a username to grant exclusive rights for file restore and VMDK/VHD export
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "authorizedUser" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\\]+$~")
     * })
     * @param string $agentName name of agent
     * @param string $authorizedUser name of the user to grant secure access
     * @return array array with keys
     *     'agentName' => name of agent
     *     'authorizedUser' => the user that was set
     */
    public function setAuthorizedUser(string $agentName, string $authorizedUser): array
    {
        // Cannot set an authorized user if active file or export restores exist. Check for any before continuing.
        $restores = $this->restoreService->getForAsset($agentName, [RestoreType::FILE, RestoreType::EXPORT]);
        if (count($restores) > 0) {
            throw new Exception(self::SECURITY_ERROR_MSG_RESTORES_EXIST_FOR_AGENT, self::SECURITY_ERROR_CODE_RESTORES_EXIST_FOR_AGENT);
        }
        $agent = $this->agentService->get($agentName);
        $agent->getShareAuth()->setUser($authorizedUser);
        $this->agentService->save($agent);
        $status[] = [
            'agentName' => $agentName,
            'authorizedUser' => $agent->getShareAuth()
        ];
        return $status;
    }

    /**
     * Get share authorization user name -- note may be 'none'
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of agent
     * @return string[]
     */
    public function getAuthorizedUser(string $agentName): array
    {
        $agent = $this->agentService->get($agentName);
        return [
            'authorizedUser' => $agent->getShareAuth()->getUser()
        ];
    }

    /**
     * Set share authorization user name -- note may be 'none' -- for all agents
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $authorizedUser Name of the user to grant secure access
     * @return array with number of agents changed
     */
    public function setAuthorizedUserAll(string $authorizedUser): array
    {
        // Cannot set an authorized user if active file or export restores exist. To help make this API call atomic
        // for all agents, check for any active file or export restores on all agents before continuing.
        $agents = $this->agentService->getAll();
        $assetKeys = array_map(function ($agent) {
            return $agent->getKeyName();
        }, $agents);
        if (count($assetKeys) === 0) {
            return [
                'length' => 0
            ];
        }
        $restores = $this->restoreService->getAllForAssets($assetKeys, [RestoreType::FILE, RestoreType::EXPORT]);
        if (count($restores) > 0) {
            throw new Exception(self::SECURITY_ERROR_MSG_RESTORES_EXIST_FOR_AGENT, self::SECURITY_ERROR_CODE_RESTORES_EXIST_FOR_AGENT);
        }

        $agentCount = 0;
        foreach ($agents as $agent) {
            $this->setAuthorizedUser($agent->getKeyName(), $authorizedUser);
            $agentCount += 1;
        }
        return [
            'length' => $agentCount
        ];
    }

    /**
     * Check if an agent is encrypted.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z0-9\._-]+$~")
     * })
     *
     * @param string $agentName The agent to check
     * @return bool Whether or not the agent is encrypted
     */
    public function isEncrypted(string $agentName): bool
    {
        $agent = $this->agentService->get($agentName);
        return $agent->getEncryption()->isEnabled();
    }


    /**
     * Unlocks the agent dataset
     *
     * FIXME This should be combined with v1/device/asset/agent/unseal
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_UNSEAL")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z0-9\._-]+$~")
     * })
     * @param string $agentName Name of the agent
     * @param string $passphrase Passphrase of the agent
     * @return bool
     */
    public function unlockAgent(string $agentName, string $passphrase): bool
    {
        try {
            $passphrase = new SecretString($passphrase);
            if ($this->encryptionService->isEncrypted($agentName) &&
                !$this->tempAccessService->isCryptTempAccessEnabled($agentName)
            ) {
                try {
                    $this->encryptionService->decryptAgentKey($agentName, $passphrase);
                } catch (Exception $e) {
                    return false;
                }
            }
            return true;
        } catch (Exception $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Changes the encryption passphrase for an agent or group of agents
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "oldPassphrase" = @Symfony\Component\Validator\Constraints\NotBlank(),
     *   "newPassphrase" = @Symfony\Component\Validator\Constraints\NotBlank(),
     *   "changeAll" = @Symfony\Component\Validator\Constraints\Type("bool")
     * })
     * @param string $assetKey Name of the agent
     * @param string $oldPassphrase Passphrase of the agent
     * @param string $newPassphrase The new passphrase to set
     * @param bool $changeAll Pass true to change all agents with the same passphrase
     * @return string[] pair names of agents whose passphrases were changed
     */
    public function changePassphrase(
        string $assetKey,
        string $oldPassphrase,
        string $newPassphrase,
        bool $changeAll = false
    ): array {
        try {
            $oldPassphrase = new SecretString($oldPassphrase);
            $newPassphrase = new SecretString($newPassphrase);

            // Make sure the original agent's passphrase is correct before we try changing other agents
            $this->encryptionService->decryptAgentKey($assetKey, $oldPassphrase);

            $agents = $changeAll ? $this->agentService->getAll() : [$this->agentService->get($assetKey)];

            $changeList = [];
            foreach ($agents as $agent) {
                if (!$agent->getEncryption()->isEnabled()) {
                    continue;
                }

                try {
                    $this->encryptionService->decryptAgentKey($agent->getKeyName(), $oldPassphrase, false);
                    $this->encryptionService->addAgentPassphrase($agent->getKeyName(), $newPassphrase);
                    $this->encryptionService->removeAgentPassphrase($agent->getKeyName(), $oldPassphrase);
                    $changeList[] = $agent->getPairName();
                } catch (AbstractPassphraseException $e) {
                    // oldPassphrase doesn't unlock this agent, skip it
                }
            }

            $this->cloudEncryptionService->uploadEncryptionKeys();

            return $changeList;
        } catch (Exception $e) {
            throw new SanitizedException($e, [$oldPassphrase, $newPassphrase]);
        }
    }
}
