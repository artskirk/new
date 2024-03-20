<?php

namespace Datto\Asset\Agent\Template;

use Datto\Asset\Retention;
use Datto\Asset\Serializer\WeeklyScheduleSerializer;
use Datto\Asset\VerificationSchedule;
use Datto\Cloud\JsonRpcClient;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * This class handles conversion of AgentTemplate objects to arrays for storage via DWI, and conversion of DWI array
 * returns into AgentTemplate objects
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentTemplateCloudClient
{
    const AGENT_TEMPLATE_ENDPOINT = 'v1/device/asset/agent/template';
    const VERIFICATION_SCHEDULE_MAP = [
        'never' => VerificationSchedule::NEVER,
        'last' => VerificationSchedule::LAST_POINT,
        'first' => VerificationSchedule::FIRST_POINT,
        'custom' => VerificationSchedule::CUSTOM_SCHEDULE,
        'offsite' => VerificationSchedule::OFFSITE
    ];

    /** @var JsonRpcClient */
    private $client;

    /** @var WeeklyScheduleSerializer */
    private $weeklyScheduleSerializer;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param JsonRpcClient $client
     * @param WeeklyScheduleSerializer $weeklyScheduleSerializer
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        JsonRpcClient $client,
        WeeklyScheduleSerializer $weeklyScheduleSerializer,
        DeviceLoggerInterface $logger
    ) {
        $this->client = $client;
        $this->weeklyScheduleSerializer = $weeklyScheduleSerializer;
        $this->logger = $logger;
    }

    /**
     * Create an AgentTemplate entry
     * @param AgentTemplate $agentTemplate
     * @return int templateId of newly created template
     */
    public function create(AgentTemplate $agentTemplate): int
    {
        $method = self::AGENT_TEMPLATE_ENDPOINT . '/create';
        $arguments = $this->arrayifyTemplate($agentTemplate);
        try {
            $result = $this->client->queryWithId($method, $arguments);
        } catch (Throwable $throwable) {
            $this->logger->debug(
                'ATC1000 Caught throwable while attempting to create AgentTemplate',
                ['exception' => $throwable]
            );
            throw new AgentTemplateException('Failed to create Agent Template.');
        }

        return $this->dearrayifyTemplate($result)->getId();
    }

    /**
     * Fetch list of agent templates
     * @return AgentTemplate[]
     */
    public function getList(): array
    {
        $method = self::AGENT_TEMPLATE_ENDPOINT . '/listTemplates';
        try {
            $result = $this->client->queryWithId($method);
        } catch (Throwable $throwable) {
            $this->logger->debug(
                'ATC1001 Caught throwable while attempting to list AgentTemplates',
                ['exception' => $throwable]
            );
            throw new AgentTemplateException('Failed to get list of Agent Templates');
        }

        $agentTemplateArray = [];
        foreach ($result as $agentSettingsTemplate) {
            try {
                $currentAgentTemplate = $this->dearrayifyTemplate($agentSettingsTemplate);
                $agentTemplateArray[] = $currentAgentTemplate;
            } catch (Throwable $throwable) {
                $this->logger->warning(
                    'ATC1002 Failed to construct AgentTemplate from DWI data',
                    ['exception' => $throwable, 'data' => serialize($agentSettingsTemplate)]
                );
            }
        }

        return $agentTemplateArray;
    }

    /**
     * Fetch a specific AgentTemplate with corresponding templateId
     * @param int $templateId
     * @return AgentTemplate
     */
    public function getTemplate(int $templateId): AgentTemplate
    {
        $method = self::AGENT_TEMPLATE_ENDPOINT . '/get';
        $arguments = ['templateID' => $templateId];
        try {
            $result = $this->client->queryWithId($method, $arguments);
        } catch (Throwable $throwable) {
            $this->logger->debug(
                'ATC1005 Caught throwable while attempting to get AgentTemplate',
                ['exception' => $throwable]
            );
            throw new AgentTemplateException('Failed to get Agent Template');
        }
        try {
            return $this->dearrayifyTemplate($result);
        } catch (Throwable $throwable) {
            $this->logger->debug(
                'ATC1006 Caught throwable while attempting to construct AgentTemplate',
                ['exception' => $throwable]
            );
            throw new AgentTemplateException('Failed to instantiate Agent Template');
        }
    }

    /**
     * Convert AgentTemplate into an array for storage in DB
     * @param AgentTemplate $agentTemplate
     * @return array
     */
    private function arrayifyTemplate(AgentTemplate $agentTemplate): array
    {
        return [
            'name' => $agentTemplate->getName(),
            'data' => [
                'local' => [
                    'backupSchedule' => $this->despaceScheduleArray(
                        $this->weeklyScheduleSerializer->serialize($agentTemplate->getLocalBackupSchedule())
                    ),
                    'retention' => [
                        'intraDaily' => $agentTemplate->getLocalRetention()->getDaily(),
                        'daily' => $agentTemplate->getLocalRetention()->getWeekly(),
                        'weekly' => $agentTemplate->getLocalRetention()->getMonthly(),
                        'max' => $agentTemplate->getLocalRetention()->getMaximum()
                    ],
                    'interval' => $agentTemplate->getBackupInterval(),
                    'snapshotTimeout' => $agentTemplate->getSnapshotTimeout()
                ],
                'offsite' => [
                    'backupSchedule' => $this->despaceScheduleArray(
                        $this->weeklyScheduleSerializer->serialize($agentTemplate->getOffsiteBackupSchedule())
                    ),
                    'priority' => $agentTemplate->getOffsitePriority(),
                    'retention' => [
                        'intraDaily' => $agentTemplate->getOffsiteRetention()->getDaily(),
                        'daily' => $agentTemplate->getOffsiteRetention()->getWeekly(),
                        'weekly' => $agentTemplate->getOffsiteRetention()->getMonthly(),
                        'max' => $agentTemplate->getOffsiteRetention()->getMaximum()
                    ],
                    'nightlyRetention' => ['limit' => $agentTemplate->getNightlyRetentionLimit()],
                    'onDemandRetention' => ['limit' => $agentTemplate->getOnDemandRetentionLimit()],
                    'replication' => [
                        'schedule' => $agentTemplate->getReplicationSchedule(),
                        'customInterval' => $agentTemplate->getReplicationCustomInterval(),
                    ]
                ],
                'ransomware' => ['enabled' => $agentTemplate->isRansomwareCheckEnabled()],
                'integrityCheck' => ['enabled' => $agentTemplate->isIntegrityCheckEnabled()],
                'verification' => [
                    'schedule' => $this->stringifyVerificationSchedule(
                        $agentTemplate->getVerificationSchedule()->getScheduleOption()
                    ),
                    'customSchedule' => $agentTemplate->getVerificationSchedule()->getScheduleOption() !==
                    VerificationSchedule::CUSTOM_SCHEDULE ? null :
                        $this->despaceScheduleArray($this->weeklyScheduleSerializer->serialize(
                            $agentTemplate->getVerificationSchedule()->getCustomSchedule()
                        )),
                    'delay' => $agentTemplate->getVerificationDelay(),
                    'errorTime' => $agentTemplate->getVerificationErrorTime()
                ]
            ]
        ];
    }

    /**
     * Return AgentTemplate from DB stored data
     * @param array $agentSettingsTemplate
     * @return AgentTemplate
     */
    private function dearrayifyTemplate(array $agentSettingsTemplate): AgentTemplate
    {
        return new AgentTemplate(
            $agentSettingsTemplate['name'],
            $this->weeklyScheduleSerializer->unserialize(
                $this->reSpaceScheduleArray($agentSettingsTemplate['data']['local']['backupSchedule'])
            ),
            $this->getLocalRetentionObject($agentSettingsTemplate),
            $agentSettingsTemplate['data']['local']['interval'],
            $agentSettingsTemplate['data']['local']['snapshotTimeout'],
            $this->weeklyScheduleSerializer->unserialize(
                $this->reSpaceScheduleArray($agentSettingsTemplate['data']['offsite']['backupSchedule'])
            ),
            $agentSettingsTemplate['data']['offsite']['priority'],
            $this->getOffsiteRetentionObject($agentSettingsTemplate),
            $agentSettingsTemplate['data']['offsite']['nightlyRetention']['limit'],
            $agentSettingsTemplate['data']['offsite']['onDemandRetention']['limit'],
            $agentSettingsTemplate['data']['offsite']['replication']['schedule'],
            $agentSettingsTemplate['data']['offsite']['replication']['customInterval'],
            $agentSettingsTemplate['data']['ransomware']['enabled'],
            $agentSettingsTemplate['data']['integrityCheck']['enabled'],
            $this->getVerificationScheduleObject($agentSettingsTemplate),
            $agentSettingsTemplate['data']['verification']['delay'],
            $agentSettingsTemplate['data']['verification']['errorTime'],
            $agentSettingsTemplate['id']
        );
    }

    /**
     * @param $agentSettingsTemplate
     * @return Retention
     */
    private function getLocalRetentionObject($agentSettingsTemplate): Retention
    {
        return new Retention(
            $agentSettingsTemplate['data']['local']['retention']['intraDaily'],
            $agentSettingsTemplate['data']['local']['retention']['daily'],
            $agentSettingsTemplate['data']['local']['retention']['weekly'],
            $agentSettingsTemplate['data']['local']['retention']['max']
        );
    }

    /**
     * @param $agentSettingsTemplate
     * @return Retention
     */
    private function getOffsiteRetentionObject($agentSettingsTemplate): Retention
    {
        return new Retention(
            $agentSettingsTemplate['data']['offsite']['retention']['intraDaily'],
            $agentSettingsTemplate['data']['offsite']['retention']['daily'],
            $agentSettingsTemplate['data']['offsite']['retention']['weekly'],
            $agentSettingsTemplate['data']['offsite']['retention']['max']
        );
    }

    /**
     * @param $agentSettingsTemplate
     * @return VerificationSchedule
     */
    private function getVerificationScheduleObject($agentSettingsTemplate): VerificationSchedule
    {
        $customSchedule = $agentSettingsTemplate['data']['verification']['customSchedule'];
        $customSchedule = $customSchedule === null ? null :
            $this->weeklyScheduleSerializer->unserialize($this->reSpaceScheduleArray($customSchedule));
        return new VerificationSchedule(
            $this->intifyVerificationSchedule($agentSettingsTemplate['data']['verification']['schedule']),
            $customSchedule
        );
    }

    /**
     * Remove spaces from schedule array, per schema doc
     * @param array $schedule
     * @return array
     */
    private function deSpaceScheduleArray(array $schedule): array
    {
        foreach ($schedule as &$day) {
            $day = str_replace(' ', '', $day);
        }
        return $schedule;
    }

    /**
     * Add spaces back to schedule array, to create WeeklySchedule object
     * @param array $schedule
     * @return array
     */
    private function reSpaceScheduleArray(array $schedule): array
    {
        $return = [];
        foreach ($schedule as $day => $backupSchedule) {
            $return[$day] = implode(' ', str_split($backupSchedule, 1));
        }
        return $return;
    }

    /**
     * Turn verification schedule integer into string for DWI
     * @param int $scheduleOption
     * @return string
     */
    private function stringifyVerificationSchedule(int $scheduleOption): string
    {
        return array_flip(self::VERIFICATION_SCHEDULE_MAP)[$scheduleOption];
    }

    /**
     * Turn string from DWI back to integer for VerificationSchedule creation
     * @param string $scheduleOption
     * @return int
     */
    private function intifyVerificationSchedule(string $scheduleOption): int
    {
        return self::VERIFICATION_SCHEDULE_MAP[$scheduleOption];
    }
}
