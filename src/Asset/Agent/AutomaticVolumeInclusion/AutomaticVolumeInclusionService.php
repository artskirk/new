<?php

namespace Datto\Asset\Agent\AutomaticVolumeInclusion;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\AutomaticVolumeInclusion\Policy\IncludeAll;
use Datto\Asset\Agent\AutomaticVolumeInclusion\Policy\IncludeOs;
use Datto\Asset\Agent\AutomaticVolumeInclusion\Policy\Noop;
use Datto\Asset\Agent\Volumes;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\Feature\CloudFeatureService;
use Psr\Log\LoggerAwareInterface;

class AutomaticVolumeInclusionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private FeatureService $featureService;
    private CloudFeatureService $cloudFeatureService;
    private AgentService $agentService;
    private DeviceConfig $deviceConfig;

    // Inclusion Policies
    private IncludeAll $includeAll;
    private IncludeOs $includeOs;
    private Noop $noop;

    public function __construct(
        FeatureService $featureService,
        CloudFeatureService $cloudFeatureService,
        AgentService $agentService,
        DeviceConfig $deviceConfig,
        IncludeAll $includeAll,
        IncludeOs $includeOs,
        Noop $noop
    ) {
        $this->featureService = $featureService;
        $this->cloudFeatureService = $cloudFeatureService;
        $this->agentService = $agentService;
        $this->deviceConfig = $deviceConfig;
        $this->includeAll = $includeAll;
        $this->includeOs = $includeOs;
        $this->noop = $noop;
    }

    /**
     * Process automatic volume inclusions for a given agent.
     *
     * @param Agent $agent
     * @param Volumes $previousVolumes
     * @return InclusionResult
     * @throws NoApplicablePolicy
     */
    public function process(Agent $agent, Volumes $previousVolumes): InclusionResult
    {
        $includedVolumes = $agent->getIncludedVolumesSettings()->getIncludedList();
        $this->logger->debug(
            "INC0001 About to apply automatic volume inclusion policy",
            [
                "keyName" => $agent->getKeyName(),
                "includedVolumes" => $includedVolumes,
                "previousVolumes" => $previousVolumes->getArrayCopy(),
            ]
        );
        if (empty($previousVolumes->getArrayCopy()) && empty($includedVolumes)) {
            $result = $this->getInitialPolicy($agent)->apply($agent);
        } else {
            $result = $this->getRollingPolicy($agent)->apply($agent);
        }

        return $result;
    }

    /**
     * Get the initial volume inclusion policy for an agent.
     *
     * @param Agent $agent
     * @return Policy
     * @throws NoApplicablePolicy
     */
    private function getInitialPolicy(Agent $agent): Policy
    {
        if ($this->deviceConfig->isAzureDevice()) {
            return $this->includeAll;
        }

        if ($this->deviceConfig->isCloudDevice()) {
            if ($this->isDtcMultiVolumeSupported($agent)) {
                return $this->includeAll;
            } else {
                return $this->includeOs;
            }
        }

        throw new NoApplicablePolicy();
    }

    /**
     * Get the rolling (aka post-initial) volume inclusion policy for an agent.
     *
     * @param Agent $agent
     * @return Policy
     * @throws NoApplicablePolicy
     */
    private function getRollingPolicy(Agent $agent): Policy
    {
        if ($this->deviceConfig->isAzureDevice()) {
            if ($this->cloudFeatureService->isSupported(CloudFeatureService::FEATURE_VOLUME_MANAGEMENT)) {
                return $this->includeOs;
            } else {
                return $this->includeAll;
            }
        }

        if ($this->deviceConfig->isCloudDevice()) {
            if ($this->cloudFeatureService->isSupported(CloudFeatureService::FEATURE_VOLUME_MANAGEMENT)) {
                return $this->includeOs;
            } elseif ($this->isDtcMultiVolumeSupported($agent)) {
                return $this->includeAll;
            } else {
                return $this->includeOs;
            }
        }

        throw new NoApplicablePolicy();
    }

    private function isDtcMultiVolumeSupported(Agent $agent): bool
    {
        return $this->featureService->isSupported(
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS_MULTI_VOLUME,
            null,
            $agent
        );
    }
}
