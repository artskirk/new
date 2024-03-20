<?php

namespace Datto\Verification\Stages;

/**
 * This class represents the details of the results of the verification stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class StageResultDetails
{
    /** @var string[] */
    private $details;

    /**
     * StageResultDetails constructor
     */
    public function __construct()
    {
        $this->details = array();
    }

    /**
     * Set the detail given by the detail name.
     * This will add a value if it does not exist or update one, if it does exist.
     *
     * @param string $detailName Name of the detail
     * @param string $detailValue Value of the detail
     */
    public function setDetail($detailName, $detailValue)
    {
        $this->details[$detailName] = $detailValue;
    }

    /**
     * Returns the detail given by the detail name if it exists.
     *
     * @param string $detailName Name of the detail
     * @return string|null Detail if it exists, null otherwise
     */
    public function getDetail($detailName)
    {
        return array_key_exists($detailName, $this->details) ? $this->details[$detailName] : null;
    }
}
