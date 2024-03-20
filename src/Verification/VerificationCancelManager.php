<?php

namespace Datto\Verification;

use Datto\Asset\Agent\Agent;
use Datto\Config\AgentStateFactory;
use Psr\Log\LoggerAwareInterface;

/**
 * This class handles the verification cancellation mechanism
 *
 * @author Peter Del Col <pdelcol@datto.com>
 */
class VerificationCancelManager
{

    /** File extension used to signal a verification cancel */
    const CANCEL_VERIFICATION_EXT = 'verificationCancelled';

    private AgentStateFactory $agentStateFactory;

    private bool $wasCancelled;

    public function __construct(AgentStateFactory $agentStateFactory)
    {
        $this->agentStateFactory = $agentStateFactory;
        $this->wasCancelled = false;
    }

    /**
     * Cancel a verification for the given asset
     *
     */
    public function cancel(Agent $agent)
    {
        $agentState = $this->agentStateFactory->create($agent->getKeyName());
        $agentState->set(self::CANCEL_VERIFICATION_EXT, 1);
        $this->wasCancelled = true;
    }

    /**
     * Check for the transaction to see if the asset is cancelling
     *
     */
    public function isCancelling(Agent $agent): bool
    {
        $agentState = $this->agentStateFactory->create($agent->getKeyName());
        return $agentState->has(self::CANCEL_VERIFICATION_EXT);
    }

    /**
     * Delete the cancel file after we are finished
     *
     */
    public function cleanup(Agent $agent)
    {
        $agentState = $this->agentStateFactory->create($agent->getKeyName());
        $agentState->clear(self::CANCEL_VERIFICATION_EXT);
    }

    /**
     * Return flag to signal that the transaction was cancelled
     *
     */
    public function wasCancelled(): bool
    {
        return $this->wasCancelled;
    }
}
