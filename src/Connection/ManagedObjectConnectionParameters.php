<?php

namespace Datto\Connection;

/**
 * Class to encapsulate the basic parameters required for dealing with a managed object in Vmwarephp. If additional
 * parameters are required for a specific type of reference, this class should be extended, not modified.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ManagedObjectConnectionParameters
{
    /** @var string */
    private $name;

    /** @var string */
    private $referenceId;

    /**
     * @param string $name
     * @param string $referenceId
     */
    public function __construct(
        $name,
        $referenceId
    ) {
        $this->name = $name;
        $this->referenceId = $referenceId;
    }

    /**
     * Get the path for the managed object
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the referenceId for the managed object
     *
     * @return string
     */
    public function getReferenceId()
    {
        return $this->referenceId;
    }
}
