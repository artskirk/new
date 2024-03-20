<?php

namespace Datto\Asset\Agent\Template;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Retention;
use Datto\Billing\Service as BillingService;

/**
 * Service for managing agent templates.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class AgentTemplateService
{
    /** @var AgentTemplateCloudClient */
    private $cloudClient;

    /** @var AgentService */
    private $agentService;

    /** @var BillingService */
    private $billingService;

    /**
     * @param AgentTemplateCloudClient $cloudClient
     * @param AgentService $agentService
     * @param BillingService $billingService
     */
    public function __construct(
        AgentTemplateCloudClient $cloudClient,
        AgentService $agentService,
        BillingService $billingService
    ) {
        $this->cloudClient = $cloudClient;
        $this->agentService = $agentService;
        $this->billingService = $billingService;
    }

    /**
     * Create a new agent template based on an existing agent.
     *
     * @param string $agentKey
     * @param string $name
     */
    public function create(string $agentKey, string $name): void
    {
        $agent = $this->agentService->get($agentKey);

        $replication = $agent->getOffsite()->getReplication();
        $template = new AgentTemplate(
            $name,
            $agent->getLocal()->getSchedule(),
            $agent->getLocal()->getRetention(),
            $agent->getLocal()->getInterval(),
            $agent->getLocal()->getTimeout(),
            $agent->getOffsite()->getSchedule(),
            $agent->getOffsite()->getPriority(),
            $agent->getOffsite()->getRetention(),
            $agent->getOffsite()->getNightlyRetentionLimit(),
            $agent->getOffsite()->getOnDemandRetentionLimit(),
            is_int($replication) ? AgentTemplate::REPLICATION_USE_INTERVAL: $replication,
            is_int($replication) ? $replication : null,
            $agent->getLocal()->isRansomwareCheckEnabled(),
            $agent->getLocal()->isIntegrityCheckEnabled(),
            $agent->getVerificationSchedule(),
            $agent->getScreenshotVerification()->getWaitTime(),
            $agent->getScreenshotVerification()->getErrorTime()
        );

        $this->cloudClient->create($template);
    }

    /**
     * List all the available agent templates for the current reseller.
     *
     * @return AgentTemplate[]
     */
    public function getList(): array
    {
        return $this->cloudClient->getList();
    }

    /**
     * Update an agent with the settings stored in a template.
     *
     * @param string $agentKey
     * @param int $templateId
     */
    public function applyTemplateToAgent(string $agentKey, int $templateId): void
    {
        $agent = $this->agentService->get($agentKey);
        $template = $this->cloudClient->getTemplate($templateId);

        $billingIsInfiniteRetention = $this->billingService->isInfiniteRetention();
        $billingIsTimeBasedRetention = $this->billingService->isTimeBasedRetention();

        $agent->getLocal()->setSchedule($template->getLocalBackupSchedule());
        $agent->getLocal()->setRetention($template->getLocalRetention());
        $agent->getLocal()->setInterval($template->getBackupInterval());
        $agent->getLocal()->setTimeout($template->getSnapshotTimeout());
        $agent->getOffsite()->setSchedule($template->getOffsiteBackupSchedule());
        $agent->getOffsite()->setPriority($template->getOffsitePriority());
        if ($billingIsInfiniteRetention) {
            $agentOffsiteRetention = $agent->getOffsite()->getRetention();
            $daily = $agentOffsiteRetention->getDaily();
            $weekly = $agentOffsiteRetention->getWeekly();
            $monthly = $agentOffsiteRetention->getMonthly();
            $maximum = $template->getOffsiteRetention()->getMaximum();
            $retention = new Retention($daily, $weekly, $monthly, $maximum);
            $agent->getOffsite()->setRetention($retention);
        } elseif (!$billingIsTimeBasedRetention) {
            $agent->getOffsite()->setRetention($template->getOffsiteRetention());
        }
        $agent->getOffsite()->setNightlyRetentionLimit($template->getNightlyRetentionLimit());
        $agent->getOffsite()->setOnDemandRetentionLimit($template->getOnDemandRetentionLimit());
        if ($template->getReplicationSchedule() === AgentTemplate::REPLICATION_USE_INTERVAL) {
            $agent->getOffsite()->setReplication($template->getReplicationCustomInterval());
        } else {
            $agent->getOffsite()->setReplication($template->getReplicationSchedule());
        }
        if ($template->isRansomwareCheckEnabled()) {
            $agent->getLocal()->enableRansomwareCheck();
        } else {
            $agent->getLocal()->disableRansomwareCheck();
        }
        $agent->getLocal()->setIntegrityCheckEnabled($template->isIntegrityCheckEnabled());
        $agent->getVerificationSchedule()->copyFrom($template->getVerificationSchedule());
        $agent->getScreenshotVerification()->setWaitTime($template->getVerificationDelay());
        $agent->getScreenshotVerification()->setErrorTime($template->getVerificationErrorTime());

        $this->agentService->save($agent);
    }
}
