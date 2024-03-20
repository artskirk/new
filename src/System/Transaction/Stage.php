<?php

namespace Datto\System\Transaction;

/**
 * A stage is a step in a transaction. It can contain an arbitrary action,
 * but must/should be reversible in the rollback action.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
interface Stage
{
    /**
     * Sets context needed for the stage to run
     *
     * @param mixed|null $context
     */
    public function setContext($context);

    /**
     * Attempts to execute this stage
     */
    public function commit();

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup();

    /**
     * Rolls back any committed changes
     */
    public function rollback();
}
