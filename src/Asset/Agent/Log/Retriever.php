<?php

namespace Datto\Asset\Agent\Log;

/**
 * Interface for retrieving Agent log information
 *
 * @author John Roland <jroland@datto.com>
 */
interface Retriever
{
    /**
     * Retrieve Agent logs
     * @param int|null $lineCount
     * @param int|null $severity
     * @return AgentLog[]
     */
    public function get(int $lineCount = null, int $severity = null);
}
