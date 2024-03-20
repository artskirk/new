<?php

namespace Datto\System\Inspection\Injector;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\AssetType;
use Datto\Connection\ConnectionType;
use Datto\Lakitu\Injection\InjectorService;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\CloneSpec;
use Datto\Verification\Application\ApplicationScriptManager;
use Datto\Virtualization\VirtualMachine;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Provide a communication layer between the PrepareVM transaction and the Lakitu-php library
 *
 * Log codes deprecated in older versions of this class INJ0001,INJ0002,VER3000,VER3001
 *
 * @author Peter Del Col <pdelcol@datto.com>
 */
class InjectorAdapter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const VOLUME_PATH_FORMAT = '%s/%s.datto';

    private ApplicationScriptManager $applicationScriptManager;
    private Filesystem $filesystem;
    private InjectorService $injectorService;
    private AgentSnapshotService $agentSnapshotService;

    public function __construct(
        ApplicationScriptManager $applicationScriptManager,
        Filesystem $filesystem,
        InjectorService $injectorService,
        AgentSnapshotService $agentSnapshotService
    ) {
        $this->applicationScriptManager = $applicationScriptManager;
        $this->filesystem = $filesystem;
        $this->injectorService = $injectorService;
        $this->agentSnapshotService = $agentSnapshotService;
    }

    public function injectLakitu(Agent $agent, string $snapshot, CloneSpec $cloneSpec): void
    {
        $osVolumeGUID = $this->getOsVolumeGuid($agent, $snapshot);
        $storageDir = $cloneSpec->getTargetMountpoint();
        $dattoFile = sprintf(self::VOLUME_PATH_FORMAT, $storageDir, $osVolumeGUID);
        if ($this->hasScripts($agent)) {
            $scripts = $this->getScripts($agent);
            $scriptFiles = array_keys($scripts);
            $this->logger->debug("VER3002 Injecting Lakitu with diagnostic scripts", ['scriptFiles' => $scriptFiles]);
            $this->injectorService->injectLakitu($dattoFile, $scriptFiles);
        } else {
            $this->logger->debug("VER3003 Injecting Lakitu without diagnostic scripts.");
            $this->injectorService->injectLakitu($dattoFile);
        }
    }

    public function shouldInjectLakitu(Agent $agent, ConnectionType $connectionType): bool
    {
        $supportedByLakitu = ($connectionType !== ConnectionType::LIBVIRT_HV()) && (
                $agent->getType() == AssetType::LINUX_AGENT
                || $agent->getType() == AssetType::WINDOWS_AGENT
                || $agent->getType() == AssetType::AGENTLESS_LINUX
                || $agent->getType() == AssetType::AGENTLESS_WINDOWS
            );
        if ($supportedByLakitu) {
            $this->logger->debug('INJ0003 Lakitu will be injected ' . $agent->getName());
            return true;
        }

        $this->logger->debug('INJ0004 Unsupported agent: ' . $agent->getName());

        return false;
    }

    /**
     * See if there are scripts to be run
     */
    public function hasScripts(Agent $agent): bool
    {
        $scriptSettings = $agent->getScriptSettings();
        $verificationSettings = $agent->getScreenshotVerification();
        $hasCustomScripts = count($scriptSettings->getScripts()) > 0;
        $hasExpectedApplications = $verificationSettings->hasExpectedApplications();
        $hasExpectedServices = $verificationSettings->hasExpectedServices();

        return $hasCustomScripts || $hasExpectedApplications || $hasExpectedServices;
    }

    private function getOsVolumeGuid(Agent $agent, string $snapshot): string
    {
        $guid = null;
        $agentName = $agent->getKeyName();
        $volumes = $this->agentSnapshotService->get($agentName, $snapshot)->getVolumes();
        foreach ($volumes as $volume) {
            if ($volume->isOsVolume()) {
                $guid = $volume->getGuid();
                break;
            }
        }

        if ($guid === null) {
            throw new Exception('Unable to locate OS volume for Lakitu injection.');
        }

        return $guid;
    }

    private function getScripts(Agent $agent): array
    {
        // Custom scripts added for this agent.
        $scriptFiles = $agent->getScriptSettings()->getScriptFilePaths();

        // Application scripts configured to run.
        $scriptFiles = $this->addExpectedApplicationScripts($scriptFiles, $agent);

        // Service scripts configured to run.
        $scriptFiles = $this->addExpectedServiceScript($scriptFiles, $agent);

        return $scriptFiles;
    }

    private function addExpectedApplicationScripts(array $scriptFiles, Agent $agent): array
    {
        try {
            $expectedApplications = $agent->getScreenshotVerification()->getExpectedApplications();
            $applicationScriptFiles = $this->applicationScriptManager->getScriptFilePaths($expectedApplications);

            foreach ($applicationScriptFiles as $applicationId => $applicationScriptFile) {
                // Expected format: scripts[<path>] = <scriptName>
                $scriptFiles[$applicationScriptFile] = $applicationId;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("INJ0014 Could not find scripts for expected applications", ['exception' => $e]);
        }

        return $scriptFiles;
    }

    private function addExpectedServiceScript(array $scriptFiles, Agent $agent): array
    {
        try {
            if ($agent->getScreenshotVerification()->hasExpectedServices()) {
                if ($agent->isType(AssetType::AGENTLESS_WINDOWS)) {
                    $scriptFiles[$this->applicationScriptManager->getGetServicesServiceEnumerationScriptPath()] =
                        ApplicationScriptManager::GET_SERVICES_SERVICE_ENUMERATION_SCRIPT;
                } else {
                    $scriptFiles[$this->applicationScriptManager->getNetStartServiceEnumerationScriptPath()] =
                        ApplicationScriptManager::NET_START_SERVICE_ENUMERATION_SCRIPT;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("INJ0013 Could not find script for expected services", ['exception' => $e]);
        }

        return $scriptFiles;
    }
}
