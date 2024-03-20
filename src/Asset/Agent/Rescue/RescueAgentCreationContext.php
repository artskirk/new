<?php

namespace Datto\Asset\Agent\Rescue;

use Datto\Asset\Agent\Agent;
use Datto\Restore\CloneSpec;
use Datto\Utility\Security\SecretString;

/**
 * Mutable shared context for the stages of Rescue Agent creation.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class RescueAgentCreationContext
{
    private Agent $sourceAgent;
    private string $rescueName;
    private string $rescueUuid;
    private int $snapshotEpoch;
    private SecretString $encryptionPassphrase;

    private ?Agent $rescueAgent = null;
    private ?CloneSpec $cloneSpec = null;

    /**
     * @param Agent $sourceAgent
     * @param string $rescueName
     * @param string $rescueUuid
     * @param int $snapshotEpoch
     * @param SecretString $encryptionPassphrase
     */
    public function __construct(
        Agent $sourceAgent,
        string $rescueName,
        string $rescueUuid,
        int $snapshotEpoch,
        SecretString $encryptionPassphrase
    ) {
        $this->sourceAgent = $sourceAgent;
        $this->rescueName = $rescueName;
        $this->rescueUuid = $rescueUuid;
        $this->snapshotEpoch = $snapshotEpoch;
        $this->encryptionPassphrase = $encryptionPassphrase;
    }

    /**
     * Get the source agent (the agent that initiated the rescue process)
     *
     * @return Agent
     */
    public function getSourceAgent(): Agent
    {
        return $this->sourceAgent;
    }

    /**
     * The epoch time of the source agent snapshot from which this rescue is being created.
     *
     * @return int
     */
    public function getSnapshotEpoch(): int
    {
        return $this->snapshotEpoch;
    }

    /**
     * Get the name of the rescue agent.
     *
     * @return string
     */
    public function getRescueAgentName(): string
    {
        return $this->rescueName;
    }

    /**
     * Get the uuid of the rescue agent.
     *
     * @return string
     */
    public function getRescueAgentUuid(): string
    {
        return $this->rescueUuid;
    }

    /**
     * Set the agent that was created for the rescue process
     *
     * @param Agent $agent
     */
    public function setRescueAgent(Agent $agent): void
    {
        $this->rescueAgent = $agent;
    }

    /**
     * Get the agent that was created for the rescue process
     */
    public function getRescueAgent(): ?Agent
    {
        return $this->rescueAgent;
    }

    /**
     * Set information about the rescue agent's clone.
     *
     * @param CloneSpec $cloneSpec
     */
    public function setCloneSpec(CloneSpec $cloneSpec): void
    {
        $this->cloneSpec = $cloneSpec;
    }

    /**
     * Get information about the rescue agent's clone.
     */
    public function getCloneSpec(): ?CloneSpec
    {
        return $this->cloneSpec;
    }

    /**
     * @return SecretString
     */
    public function getEncryptionPassphrase(): SecretString
    {
        return $this->encryptionPassphrase;
    }
}
