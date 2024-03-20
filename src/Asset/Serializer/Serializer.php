<?php

namespace Datto\Asset\Serializer;

/**
 * A serializer transforms an object into an array, and is able to convert
 * it back to set object. It is used to persist the object model into one or many
 * files.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
interface Serializer
{
    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param mixed $object object to convert into an array
     * @return mixed Serialized object
     */
    public function serialize($object);

    /**
     * Create an object from the given array.
     *
     * @param mixed $serializedObject Serialized object
     * @return mixed object built with the array's data
     */
    public function unserialize($serializedObject);
}
