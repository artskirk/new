<?php

namespace Datto\Mercury;

/**
 * Mercury Target Does Not Exist Exception
 *
 * @author Mario Rial <mrial@datto.com>
 */
class MercuryTargetDoesNotExistException extends \Exception
{
    /** @var  string */
    private $targetName;

    /**
     * MercuryTargetDoesNotExistException constructor.
     *
     * @param string $targetName
     */
    public function __construct($targetName)
    {
        parent::__construct("The target: $targetName does not exist");
        $this->targetName = $targetName;
    }

    /**
     * @return string
     */
    public function getTargetName()
    {
        return $this->targetName;
    }
}
