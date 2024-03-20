<?php

namespace Datto\Asset\Agent\DirectToCloud;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentException;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLogger;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;

/**
 * Logic for DTC agent protected system configuration requests (i.e., update config table in the DB)
 *
 * Jess Gentner <jgentner@datto.com>
 */
class ProtectedSystemConfigurationService
{
    /** @var AgentService */
    private $agentService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var FeatureService */
    private $featureService;

    /**
     * @param AgentService $agentService
     * @param DeviceLoggerInterface $logger
     * @param FeatureService $featureService
     */
    public function __construct(
        AgentService $agentService,
        DeviceLoggerInterface $logger,
        FeatureService $featureService
    ) {
        $this->agentService = $agentService;
        $this->logger = $logger;
        $this->featureService = $featureService;
    }

    /**
     * @param Agent $agent
     * @return array|null
     */
    public function get(Agent $agent)
    {
        $settings = $agent->getDirectToCloudAgentSettings();
        if (!isset($settings)) {
            return null;
        }

        return $settings->getProtectedSystemAgentConfigRequest();
    }

    /**
     * @param array $agents
     * @param string $configuration
     */
    public function set(array $agents, string $configuration): void
    {
        // Verify that there are no outstanding requests for any agent.
        $hasExistingRequest = $this->isAnyRequestOutstanding($agents);

        if ($hasExistingRequest === true) {
            throw new AgentException('One or more agents have an outstanding configuration request.'
                . '  Use the merge or overwrite option to modify an outstanding request.');
        }

        $this->overwrite($agents, $configuration);
    }

    /**
     * @param array $agents
     * @param string $configuration
     */
    public function overwrite(array $agents, string $configuration): void
    {
        // cursory check of the supplied configuration
        $configuration = $this->convertConfiguration($configuration);

        /** @var Agent $agent */
        foreach ($agents as $agent) {
            $this->logger->setAssetContext($agent->getKeyName());
            if (!$this->featureService->isSupported(
                FeatureService::FEATURE_PROTECTED_SYSTEM_CONFIGURABLE,
                null,
                $agent
            )) {
                $this->logger->info('DTC0020 Request to overwrite configuration skipped because of missing feature');
                continue;
            }

            $settings = $agent->getDirectToCloudAgentSettings();
            if (!isset($settings)) {
                continue;
            }

            $settings->setProtectedSystemAgentConfigRequest($configuration);
            $this->agentService->save($agent);

            $this->logger->info('DTC0021 Config request set', ['agentConfigRequest' => $settings->getProtectedSystemAgentConfigRequest()]);
        }

        $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);
    }

    /**
     * @param array $agents
     * @param string $additionalConfiguration
     */
    public function merge(array $agents, string $additionalConfiguration): void
    {
        $additionalConfiguration = $this->convertConfiguration($additionalConfiguration);

        /** @var Agent $agent */
        foreach ($agents as $agent) {
            $this->logger->setAssetContext($agent->getKeyName());
            if (!$this->featureService->isSupported(
                FeatureService::FEATURE_PROTECTED_SYSTEM_CONFIGURABLE,
                null,
                $agent
            )) {
                $this->logger->info('DTC0022 Request to merge configuration skipped because of missing feature');
                continue;
            }

            $settings = $agent->getDirectToCloudAgentSettings();
            if (!isset($settings)) {
                continue;
            }

            $configuration = $additionalConfiguration;
            if ($this->isRequestOutstanding($agent)) {
                $existingConfiguration = $settings->getProtectedSystemAgentConfigRequest();
                $configuration = array_merge($existingConfiguration, $configuration);
            }

            $settings->setProtectedSystemAgentConfigRequest($configuration);
            $this->agentService->save($agent);

            $this->logger->info('DTC0023 Config request set', ['agentConfigRequest' => $settings->getProtectedSystemAgentConfigRequest()]);
        }

        $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);
    }

    /**
     * @param Agent $agent
     * @return object|null
     */
    public function getRequest(Agent $agent)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_PROTECTED_SYSTEM_CONFIGURABLE, null, $agent)) {
            return null;
        }

        $request = null;
        $settings = $agent->getDirectToCloudAgentSettings();
        if (isset($settings)) {
            $request = $settings->getProtectedSystemAgentConfigRequest();
        }

        return (object)$request;
    }

    /**
     * @param Agent $agent
     * @param array $processedRequest
     * @return object
     */
    public function resetConfigRequestIfUnchanged(Agent $agent, array $processedRequest)
    {
        $settings = $agent->getDirectToCloudAgentSettings();
        $request = null;

        if (isset($settings)) {
            // snapctl may have changed the config request via flags such as merge/overwrite
            // so make sure that what was processed by the agent is current.  Otherwise,
            // let it take the new request the next time it checks by leaving the current value.
            if ($settings->getProtectedSystemAgentConfigRequest() == $processedRequest) {
                $settings->setProtectedSystemAgentConfigRequest(null);
                $this->agentService->save($agent);
            }

            $request = $settings->getProtectedSystemAgentConfigRequest();
        }

        return (object)$request;
    }

    /**
     * @param Agent $agent The agent
     * @return bool True if the agent had a configuration set
     */
    private function isRequestOutstanding(Agent $agent)
    {
        $settings = $agent->getDirectToCloudAgentSettings();
        if (isset($settings)) {
            $configuration = $settings->getProtectedSystemAgentConfigRequest();
            if (isset($configuration)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $configuration
     * @return array
     */
    private function convertConfiguration(string $configuration)
    {
        // cursory check of the supplied configuration
        $configuration = json_decode($configuration, true);
        if (!is_array($configuration) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Received malformed json encoded arguments: ' .
                json_last_error_msg()
            );
        }

        return $configuration;
    }

    /**
     * @param array $agents
     * @return bool
     */
    private function isAnyRequestOutstanding(array $agents)
    {
        $hasExistingRequest = false;

        /** @var Agent $agent */
        foreach ($agents as $agent) {
            if (!$this->featureService->isSupported(
                FeatureService::FEATURE_PROTECTED_SYSTEM_CONFIGURABLE,
                null,
                $agent
            )) {
                continue;
            }

            $hasExistingRequest = $this->isRequestOutstanding($agent);
            if ($hasExistingRequest === true) {
                break;
            }
        }

        return $hasExistingRequest;
    }
}
