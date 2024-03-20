<?php

namespace Datto\Events\Common;

/**
 * Trait RemoveNullProperties removes any properties with a value of null from the JSON representation of an object
 *
 * @author Matt Coleman <mcoleman@datto.com>
 */
trait RemoveNullProperties
{
    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this), function ($value) {
            return !is_null($value);
        });
    }
}
