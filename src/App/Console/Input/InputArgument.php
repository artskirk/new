<?php

namespace Datto\App\Console\Input;

/**
 * Class InputArgument
 * This class mimics a subset of the functionality of the Symfony InputArgument class.  Note that in this case
 * this is just the constants.  The other methods have been added to enable the rest of the configure addArgument
 * functionality to be reproduced.
 */
class InputArgument
{
    const REQUIRED = 1;
    const OPTIONAL = 2;

    public $name;
    public $description = '';
    public $defaultValue = null;
    private $requiredness = self::OPTIONAL;

    /**
     * @param string|null $name
     * @return $this
     */
    public function setName($name = null)
    {
        if (is_null($name)) {
            throw new InputArgumentException('Arguments must have a name.');
        }
        $this->name = $name;
        return $this;
    }

    /**
     * @param int $requiredness
     * @return $this
     */
    public function setRequiredness($requiredness = self::OPTIONAL)
    {
        $this->requiredness = $requiredness;
        return $this;
    }

    /**
     * @param string $description
     * @return $this
     */
    public function setDescription($description = '')
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @param mixed|null $defaultValue
     * @return $this
     */
    public function setDefaultValue($defaultValue = null)
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * @return bool
     */
    public function isRequired()
    {
        return $this->requiredness === self::REQUIRED;
    }
}
