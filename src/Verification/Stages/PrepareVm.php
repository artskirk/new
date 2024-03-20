<?php

namespace Datto\Verification\Stages;

use Datto\Asset\Agent\Encryption\librijndael2;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Feature\FeatureService;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\System\Inspection\Injector\InjectorAdapter;
use Datto\System\Transaction\TransactionException;
use Datto\Verification\VerificationResultType;
use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use RuntimeException;
use Throwable;

/**
 * Prepare the VM for verification.
 *
 * Logs messages with the VER prefix in the range 0300-0399.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class PrepareVm extends VerificationStage
{
    const DETAILS_INJECTION_SUCCEEDED = 'InjectionSucceeded';
    const DEFAULT_VERIFICATION_MEMORY_IN_MIB = 3072;  // 3 GIB
    const LOW_RESOURCE_VERIFICATION_MEMORY_IN_MIB = 1536;  // 1.5 GIB

    private AgentConfigFactory $agentConfigFactory;
    private VirtualMachineService $virtualMachineService;
    private InjectorAdapter $injectorAdapter;
    private FeatureService $featureService;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        VirtualMachineService $virtualMachineService,
        InjectorAdapter $injectorAdapter,
        FeatureService $featureService
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->virtualMachineService = $virtualMachineService;
        $this->injectorAdapter = $injectorAdapter;
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        try {
            $agent = $this->context->getAgent();
            /** @var AbstractLibvirtConnection $connection */
            $connection = $this->context->getConnection();
            $assetName = $this->context->getAgent()->getKeyName();
            $snapshot = $this->context->getSnapshotEpoch();

            $this->logger->debug('VER0300 preparing verification VM.', ['assetName' => $assetName, 'snapshot' => $snapshot]);

            $willInjectLakitu = $this->injectorAdapter->shouldInjectLakitu($agent, $connection->getType());

            $cloneSpec = $this->context->getCloneSpec();
            $vmName = $this->virtualMachineService->generateVmName(
                $agent,
                $cloneSpec->getSuffix()
            );
            $vmSettings = $this->prepareVmSettings();

            try {
                $vm = $this->virtualMachineService->createAgentVm(
                    $agent,
                    $vmName,
                    $cloneSpec,
                    $cloneSpec->getSnapshotName(),
                    $connection,
                    $vmSettings,
                    $willInjectLakitu,
                    false,
                    $this->featureService->isSupported(FeatureService::FEATURE_SKIP_VERIFICATION),
                    $this->injectorAdapter
                );

                if ($willInjectLakitu && !is_null($vm)) {
                    $vm->addSerialPort();
                    $this->context->setLakituInjected(true);
                }
                // set virtual machine for use by other stages
                $this->context->setVirtualMachine($vm);
                if ($vm === null) {
                    $this->logger->warning('VER0307 createAgentVm returned null with SKIP_VERIFICATION enabled');
                    $this->context->setOsUpdatePending(true);
                    $this->setResult(
                        VerificationResultType::SKIPPED(),
                        "Pending reboot detected with verification skip enabled"
                    );
                    return;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'VER0301 failed to create virtual machine',
                    ['assetSnapshot' => "$assetName@$snapshot", 'exception' => $e]
                );
                $this->setResult(
                    VerificationResultType::FAILURE_INTERMITTENT(),
                    "Failed to create VM template, createVM exception : " . $e->getMessage()
                );
                throw new TransactionException('Prepare VM failed.', $e->getCode(), $e);
            }

            try {
                // Start the VM
                $this->logger->debug('VER0304 Starting VM');
                $vm->start();
            } catch (Throwable $e) {
                $this->logger->debug('VER0305 Failed to start screenshot VM', ['exception' => $e]);
                $this->setResult(
                    VerificationResultType::FAILURE_INTERMITTENT(),
                    sprintf('Failed to start screenshot VM: %s ', $e->getMessage())
                );
            }
        } catch (TransactionException $e) {
            // This is to intercept TransactionException and leave the result unaltered.
            throw $e;
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        if (!$this->result) {
            $this->setResult(VerificationResultType::SUCCESS());
        }

        $resultDetails = $this->getResult()->getDetails();
        $resultDetails->setDetail(static::DETAILS_INJECTION_SUCCEEDED, $this->context->isLakituInjected());

        if (!$this->result->didSucceed()) {
            throw new TransactionException('Prepare VM failed. Error message: ' . $this->result->getErrorMessage());
        }
    }

    protected function prepareVmSettings(): AbstractVmSettings
    {
        $connectionType = $this->context->getConnection()->getType();

        $agent = $this->context->getAgent();

        $config = $this->agentConfigFactory->create($agent->getKeyName());

        $lowResourceVerifications = $this->featureService->isSupported(
            FeatureService::FEATURE_LOW_RESOURCE_VERIFICATIONS
        );

        $config->loadRecord($settings = VmSettingsFactory::create($connectionType));
        $settings->setCpuCount(2);
        $settings->setNetworkMode('NONE');
        $settings->setRam(
            $lowResourceVerifications ?
            self::LOW_RESOURCE_VERIFICATION_MEMORY_IN_MIB :
            self::DEFAULT_VERIFICATION_MEMORY_IN_MIB
        );

        // apply screenshot overrides
        $override = $this->context->getScreenshotOverride();
        $cpuCount = $override->getOverrideCpuCores();
        $ramInMiB = $override->getOverrideRamInMiB();

        if (!empty($cpuCount)) {
            $settings->setCpuCount($cpuCount);
        }

        if (!empty($ramInMiB)) {
            $settings->setRam($ramInMiB);
        }

        return $settings;
    }
    
    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        $agent = $this->context->getAgent();
        $vm = null;

        try {
            $vm = $this->context->getVirtualMachine();
        } catch (RuntimeException $e) {
            $this->logger->debug(
                'VER0308 Failed to retrieve the VM.',
                ['exception' => $e]
            );
        }

        if (!is_null($vm)) {
            try {
                $this->virtualMachineService->destroyAgentVm($agent, $vm);
                $this->context->setVirtualMachine(null);
            } catch (Throwable $e) {
                $this->logger->error(
                    'VER0306 Failed to destroy virtual machine.',
                    ['uuid ' => $vm->getUuid(), 'name' => $vm->getName(), 'exception' => $e]
                );
            }
        }
    }
}
