<?php
namespace Datto\Finder;

use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Extend Symfony Finder with the ability to reset itself for reuse
 */
class ResettableFinder extends Finder
{
    /**
     * Reset the Finder to allow new searches to be performed
     */
    public function reset()
    {
        $this->setParentProperty('mode', 0);
        $this->setParentProperty('followLinks', false);
        $this->setParentProperty('sort', false);
        $this->setParentProperty('ignore', static::IGNORE_VCS_FILES | static::IGNORE_DOT_FILES);
        $this->setParentProperty('ignoreUnreadableDirs', false);

        $arraysToClear = array(
            'names',
            'notNames',
            'exclude',
            'filters',
            'depths',
            'sizes',
            'dirs',
            'dates',
            'iterators',
            'contains',
            'notContains',
            'paths',
            'notPaths'
        );
        foreach ($arraysToClear as $array) {
            $this->setParentProperty($array, array());
        }
    }

    /**
     * Set a private property on the parent class
     *
     * @param $property
     *   The name of the property being set
     * @param $value
     *   The value to set the property to
     */
    private function setParentProperty($property, $value)
    {
        $selfReflection = new ReflectionClass($this);
        $parentReflection = $selfReflection->getParentClass();
        $propertyReflection = $parentReflection->getProperty($property);
        $propertyReflection->setAccessible(true);
        $propertyReflection->setValue($this, $value);
    }
}
