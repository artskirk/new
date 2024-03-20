<?php

namespace Datto\Util;

/**
 * This class is used for turning an ini config represented in an associative array into a proper ini string. Most of
 * this functionality is duplicated from Zend's IniWriter implementation (Zend\Config\Writer\Ini).
 */
class IniTranslator
{
    /**
     * Separator for nesting levels of configuration data identifiers.
     *
     * @var string
     */
    const NEST_SEPARATOR = '.';

    /**
     *  Converts an associative array into an ini string.
     *
     * @param  array $config
     * @return string
     */
    public function stringify($config)
    {
        $iniString = '';

        $config = $this->sortRootElements($config);

        foreach ($config as $sectionName => $data) {
            if (!is_array($data)) {
                $iniString .= $sectionName
                    .  ' = '
                    .  $this->prepareValue($data)
                    .  "\n";
            } else {
                $iniString .= '[' . $sectionName . ']' . "\n"
                    .  $this->addBranch($data)
                    .  "\n";
            }
        }

        return $iniString;
    }

    /**
     * Add a branch to an INI string recursively.
     *
     * @param  array $config
     * @param  array $parents
     * @return string
     */
    private function addBranch($config, $parents = array())
    {
        $iniString = '';

        foreach ($config as $key => $value) {
            $group = array_merge($parents, array($key));

            if (is_array($value)) {
                $iniString .= $this->addBranch($value, $group);
            } else {
                $iniString .= implode(self::NEST_SEPARATOR, $group)
                    .  ' = '
                    .  $this->prepareValue($value)
                    .  "\n";
            }
        }

        return $iniString;
    }

    /**
     * Prepare a value for INI.
     *
     * @param  mixed $value
     * @return string
     */
    private function prepareValue($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return ($value ? 'true' : 'false');
        } elseif (false === strpos($value, '"')) {
            return '"' . $value .  '"';
        } else {
            throw new \Exception('Value can not contain double quotes');
        }
    }

    /**
     * Root elements that are not assigned to any section needs to be on the
     * top of config.
     *
     * @param array $config
     * @return array
     */
    private function sortRootElements($config)
    {
        $sections = array();

        // Remove sections from config array.
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $sections[$key] = $value;
                unset($config[$key]);
            }
        }

        // Read sections to the end.
        foreach ($sections as $key => $value) {
            $config[$key] = $value;
        }

        return $config;
    }
}
