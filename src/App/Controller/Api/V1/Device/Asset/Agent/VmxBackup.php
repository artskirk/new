<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\Windows\WindowsAgent;

/**
 * This class contains the API endpoints for changing VMX Backup Settings. Note that the backend functionality
 * for these settings has changed; the VMX file is no longer backed up; we simply copy the VM settings over from
 * an ESX hypervisor at the time that an agent is virtualized. These settings are only meaningful for a very specific
 * type of agent: a Windows system that is running on a VMWare hypervisor but is paired as an agent. Also note that
 * the frontend now uses the new wording, "VM Configuration Backup", whereas the backend still uses the deprecated
 * wording, "VMX Backup".
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
 * @author Matt Cheman <mcheman@datto.com>
 */
class VmxBackup extends AbstractAgentEndpoint
{
    /**
     * Enable backing up of .vmx configuration file
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of agent
     */
    public function enableConfigBackup(string $agentName): void
    {
        /** @var WindowsAgent $agent */
        $agent = $this->agentService->get($agentName);
        $agent->getVmxBackupSettings()->setEnabled(true);
        $this->agentService->save($agent);
    }

    /**
     * Disable backing up of .vmx configuration file
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of agent
     */
    public function disableConfigBackup(string $agentName): void
    {
        /** @var WindowsAgent $agent */
        $agent = $this->agentService->get($agentName);
        $agent->getVmxBackupSettings()->setEnabled(false);
        $this->agentService->save($agent);
    }
}
