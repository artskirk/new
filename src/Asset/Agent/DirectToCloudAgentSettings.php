<?php

namespace Datto\Asset\Agent;

/**
 * Contains any direct-to-cloud specific information.
 *
 * @author Jess Gentner <jgentner@datto.com>
 */
class DirectToCloudAgentSettings
{
    /** @var array|null */
    private $pendingProtectedSystemAgentConfigRequest;

    /**
     * @param array|null $pendingProtectedSystemAgentConfigRequest
     */
    public function __construct(
        array $pendingProtectedSystemAgentConfigRequest = null
    ) {
        $this->pendingProtectedSystemAgentConfigRequest = $pendingProtectedSystemAgentConfigRequest;
    }

    /**
     * @return array|null
     */
    public function getProtectedSystemAgentConfigRequest()
    {
        return $this->pendingProtectedSystemAgentConfigRequest;
    }

    /**
     * @param array|null $request
     */
    public function setProtectedSystemAgentConfigRequest(array $request = null): void
    {
        $this->pendingProtectedSystemAgentConfigRequest = $request;
    }
}
