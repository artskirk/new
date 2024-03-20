<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\VirtualizationSettings;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\OriginDevice;
use Datto\Asset\Retention;
use Datto\Billing\Service as BillingService;
use Datto\Config\AgentStateFactory;
use Datto\Config\DeviceConfig;
use Datto\Service\AssetManagement\Create\CreateAgentProgress;
use Datto\Util\OsFamily;

/**
 * Responsible for configuring any remaining agent settings before it's considered created to the user.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateAgent extends AbstractCreateStage
{
    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var AgentService */
    private $agentService;

    /** @var BillingService */
    private $billingService;

    /** @var VolumesService */
    private $volumesService;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        DeviceConfig $deviceConfig,
        AgentStateFactory $agentStateFactory,
        AgentService $agentService,
        BillingService $billingService,
        VolumesService $volumesService,
        EncryptionService $encryptionService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->agentStateFactory = $agentStateFactory;
        $this->agentService = $agentService;
        $this->billingService = $billingService;
        $this->volumesService = $volumesService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();

        if ($this->context->needsEncryption()) {
            $this->encryptionService->encryptAgent($this->context->getAgentKeyName(), $this->context->getPassword());
        }

        $createProgress->setState(CreateAgentProgress::DEFAULTS);
        $agentState->saveRecord($createProgress);

        $agent = $this->agentService->get($this->context->getAgentKeyName());

        // The user can choose to copy some settings from a different agent
        if (!empty($this->context->getAgentKeyToCopy())) {
            $agentToCopy = $this->agentService->get($this->context->getAgentKeyToCopy());
            $this->context->getLogger()->info('PAR0102 Copying settings from agent: ' . $this->context->getAgentKeyToCopy());
            $agent->copyFrom($agentToCopy);
        }

        $agent->getLocal()->setIntegrityCheckEnabled(true);

        $deviceId = $this->deviceConfig->getDeviceId();
        $resellerId = $this->deviceConfig->getResellerId();
        $originDevice = new OriginDevice($deviceId, $resellerId, false);
        $agent->setOriginDevice($originDevice);

        $agent->setOffsiteTarget($this->context->getOffsiteTarget()); // todo make sure ui passes correct offsite target for copy agent

        $defaultRetention = Retention::createApplicableDefault($this->billingService);
        $agent->getOffsite()->setRetention($defaultRetention);

        $this->volumesService->setupDefaultIncludes($agent);

        $os = $agent->getOperatingSystem();
        if ($os->getOsFamily() === OsFamily::WINDOWS() && method_exists($agent, 'getVirtualizationSettings')) {
            $legacyNtKernelVersion = version_compare($os->getVersion(), '5.3', '<');
            $win2000 = strpos($os->getName() . $os->getVersion(), '2000');
            if ($legacyNtKernelVersion || $win2000) {
                $agent->getVirtualizationSettings()->setEnvironment(VirtualizationSettings::ENVIRONMENT_LEGACY);
            }
        }

        $agent->initialConfiguration();
        $this->agentService->save($agent);

        // agent is now considered created to the user
        $createProgress->setState(CreateAgentProgress::UI_SUCCESS);
        $agentState->saveRecord($createProgress);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // none
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // PairAgent.php takes care of deleting key files
    }
}
