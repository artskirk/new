<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\VerificationSchedule;
use Datto\Common\Resource\ProcessFactory;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Exception;

/**
 * This class contains the API endpoints for working with agent screenshot schedules.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ScreenshotSchedule extends AbstractAgentEndpoint
{
    private ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory,
        AgentService $agentService
    ) {
        parent::__construct($agentService);
        $this->processFactory = $processFactory;
    }

    /**
     * Set an agent's screenshot schedule
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Agent name
     * @param string $scheduleType The schedule type (VerificationSchedule::SCHEDULE_OPTIONS)
     * @param array $schedule A new schedule to set (7x24 table with bool or 1/0)
     * @param int $waitTime The additional wait time for screenshots
     */
    public function setSchedule($agentName, $scheduleType, $schedule, $waitTime): void
    {
        if (!in_array($scheduleType, VerificationSchedule::SCHEDULE_OPTIONS, true)) {
            throw new Exception("Not a valid schedule type.");
        } elseif (!in_array($waitTime, ScreenshotVerificationSettings::VALID_WAIT_TIMES, true)) {
            throw new Exception("Not a valid wait time.");
        }

        /** @var Agent $agent */
        $agent = $this->agentService->get($agentName);

        if ($scheduleType === VerificationSchedule::CUSTOM_SCHEDULE) {
            $newSchedule = new WeeklySchedule();
            $newSchedule->setSchedule($schedule);
            if ($newSchedule->isValidWithFilter($agent->getLocal()->getSchedule())) {
                $agent->getVerificationSchedule()->setCustomSchedule($newSchedule);
            } else {
                throw new Exception("This screenshot schedule is incompatible with the agent's local schedule");
            }
        }
        $agent->getVerificationSchedule()->setScheduleOption($scheduleType);
        $agent->getScreenshotVerification()->setWaitTime($waitTime);
        $this->agentService->save($agent);
    }

    /**
     * Set all agent's screenshot schedules
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $scheduleType The schedule type (last/first/custom/never)
     * @param array $schedule A new schedule to set (7x24 table with bool or 1/0)
     * @param int $waitTime The additional wait time for screenshots
     * @return string[] a list of agent names that were updated
     */
    public function setScheduleAll($scheduleType, $schedule, $waitTime)
    {
        if (!in_array($scheduleType, VerificationSchedule::SCHEDULE_OPTIONS, true)) {
            throw new Exception("Not a valid schedule option.");
        } elseif (!in_array($waitTime, ScreenshotVerificationSettings::VALID_WAIT_TIMES, true)) {
            throw new Exception("Not a valid wait time.");
        }

        /** @var Agent[] $agents */
        $agents = $this->agentService->getAllActiveLocal();
        $status = array();
        foreach ($agents as $agent) {
            if ($scheduleType === VerificationSchedule::CUSTOM_SCHEDULE) {
                $newSchedule = new WeeklySchedule();
                $newSchedule->setSchedule($schedule);
                if ($newSchedule->isValidWithFilter($agent->getLocal()->getSchedule())) {
                    $agent->getVerificationSchedule()->setCustomSchedule($newSchedule);
                } else {
                    continue;
                }
            }
            $agent->getVerificationSchedule()->setScheduleOption($scheduleType);
            $agent->getScreenshotVerification()->setWaitTime($waitTime);
            $this->agentService->save($agent);
            $status[] = $agent->getKeyName();
        }
        return $status;
    }

    /**
     * Set an agent's screenshot error threshold.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $agentName
     * @param float $errorThreshold
     */
    public function setErrorThreshold($agentName, $errorThreshold): void
    {
        if ($errorThreshold < 0) {
            throw new Exception("Error threshold must be non-negative.");
        }

        /** @var Agent $agent */
        $agent = $this->agentService->get($agentName);
        $agent->getScreenshotVerification()->setErrorTime($errorThreshold);
        $this->agentService->save($agent);
    }

    /**
     * Set all agent's screenshot error thresholds.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param float $errorThreshold
     * @return string[] A list of names of updated agents
     */
    public function setErrorThresholdAll($errorThreshold)
    {
        if ($errorThreshold < 0) {
            throw new Exception("Error threshold must be non-negative.");
        }

        /** @var Agent[] $agents */
        $agents = $this->agentService->getAllActiveLocal();
        $status = array();
        foreach ($agents as $agent) {
            $agent->getScreenshotVerification()->setErrorTime($errorThreshold);
            $this->agentService->save($agent);
            $status[] = $agent->getName();
        }
        return $status;
    }

    /**
     * Send a test emails to all addresses in the list.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $agentName
     */
    public function sendTestEmail($agentName): void
    {
        $process = $this->processFactory
            ->get(['snapctl', 'testEmail', $agentName, 'screenshots'])
            ->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new Exception("Emails were not sent successfully.");
        }
    }
}
