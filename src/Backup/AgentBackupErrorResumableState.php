<?php

namespace Datto\Backup;

use JsonSerializable;

/**
 * This class holds the state of resumable backup errors for a given agent.
 */
class AgentBackupErrorResumableState implements Jsonserializable
{
    private string $agentUuid;
    private int $retries;
    private ?int $lastNotificationTimestamp;

    public function __construct(
        string $agentUuid,
        int $retries,
        ?int $lastNotificationTimestamp = null
    ) {
        $this->agentUuid = $agentUuid;
        $this->retries = $retries;
        $this->lastNotificationTimestamp = $lastNotificationTimestamp;
    }

    public function getAgentUuid(): string
    {
        return $this->agentUuid;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function setRetries(int $retries): void
    {
        $this->retries = $retries;
    }

    public function getLastNotificationTimestamp(): ?int
    {
        return $this->lastNotificationTimestamp;
    }

    public function setLastNotificationTimestamp(int $notificationTime): void
    {
        $this->lastNotificationTimestamp = $notificationTime;
    }

    public function jsonSerialize(): array
    {
        return [
            'agentUuid' => $this->agentUuid,
            'retries' => $this->retries,
            'lastNotificationTimestamp' => $this->lastNotificationTimestamp
        ];
    }

    /**
     * @return AgentBackupErrorResumableState[] indexed by agent UUID
     */
    public static function hydrateArray(array $arr): array
    {
        $objects = [];
        foreach ($arr as $d) {
            $objects[$d['agentUuid']] = new AgentBackupErrorResumableState(
                $d['agentUuid'],
                $d['retries'],
                $d['lastNotificationTimestamp'],
            );
        }
        return $objects;
    }
}
