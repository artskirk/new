<?php

namespace Datto\Verification;

use Datto\Verification\Stages\StageResult;

/**
 * This class holds the context that is used by the notifiers.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class NotificationContext
{
    /** @var StageResult[] */
    private $stageResults;

    /**
     * Construct a NotificationContext object.
     */
    public function __construct()
    {
        $this->stageResults = array();
    }

    /**
     * Add the verification stage to the notification context.
     *
     * @param string $stageName Name of the verification stage.
     * @param StageResult $result Result returned from the verification stage.
     */
    public function add($stageName, StageResult $result)
    {
        $this->stageResults[$stageName] = $result;
    }

    /**
     * Return the results for the given stage.
     *
     * @param string $stageName Name of the verification stage.
     * @return StageResult|null Result returned from the verification stage.
     */
    public function getResults($stageName)
    {
        return $this->stageResults[$stageName];
    }

    /**
     * See if the results exists for given stage name.
     *
     * @param string $stageName Name of the verification stage.
     * @return bool True if the results exist
     */
    public function exists($stageName)
    {
        return array_key_exists($stageName, $this->stageResults);
    }
}
