<?php

namespace Datto\Samba;

use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * This class is intended to abstract the sections of smb.conf-like configurations.
 *
 * The name of the section as well as the section properties/values are stored
 * in protected class properties. Explicit getters and setters should dictate
 * changes from external sources as further definition/validation may be
 * required by extending subclasses.
 *
 * @author Evan Buther <evan.buther@dattobackup.com>
 */
class SambaSection
{
    /**
     * @var string  The name of the section
     */
    public $name;

    /**
     * @var array  The properties of the section
     */
    public $properties = [];

    /**
     * @var array  The queued properties / changes of the section
     */
    public $queuedProperties = [];

    /**
     * @var array List of property keys that might occur multiple times. These will be transformed to arrays.
     */
    public $allowedDuplicatePropertyKeys = ['include'];

    /**
     * @var boolean  Whether or not this section is marked for removal
     */
    public $removalFlag = false;

    /**
     * Assigns the section name
     *
     * @param string $sectionName
     */
    public function __construct(string $sectionName = null)
    {
        $this->name = $sectionName;
    }

    /**
     * Clones the object and appends '-clone' to the name (to avoid collisions)
     */
    public function __clone()
    {
        // Clones must have another name as they may collide
        $this->name = $this->name . '-clone';
    }

    /**
     * Factory method to instantiate either a SambaSection or SambaShare object depending on the name
     *
     * @param string $sectionName
     * @param UserService|null $userService
     * @param Filesystem|null $filesystem
     * @return SambaSection
     */
    public static function create(
        string $sectionName,
        UserService $userService = null,
        Filesystem $filesystem = null
    ): SambaSection {
        // Special sections that only belong in the smb.conf
        $specialSections = array
        (
            'global',
            'homes',
            'printers',
            'IPC$',
            'print$'
        );

        if (in_array($sectionName, $specialSections) || static::isComment($sectionName)) {
            // This is a special section, return the section object.  Note that if a file contains sections but has
            // a comment at the beginning, we can get a "comment" section.
            return new self($sectionName);
        }

        // This is not a special section, return a share object
        return new SambaShare($sectionName, $userService, $filesystem);
    }

    /**
     * Determine if a line is a comment or not.
     *
     * @param string $configLine
     * @return bool
     */
    public static function isComment(string $configLine): bool
    {
        $line = trim($configLine);
        $isHashComment = strpos($line, '#') === 0;
        $isSemicolonComment = strpos($line, ';') === 0;
        return $isHashComment || $isSemicolonComment;
    }

    /**
     * Loads the section based on the passed section name and string read from the source file
     *
     * @param string $sectionName The name of the section being loaded
     * @param string $sectionString The raw string from the configuration file containing the section definition
     * @return bool  Whether or not the section was loaded
     */
    public function load(string $sectionName, string $sectionString): bool
    {
        $this->properties = array();

        if ($this->name === null) {
            // Only change if no name has been set (reloads)
            $this->name = $sectionName;
        }

        $sectionLines = explode("\n", $sectionString);

        foreach ($sectionLines as $sectionLine) {
            $line = trim($sectionLine);

            if (preg_match('/^\[(.+?)\]$/', $line, $match)) {
                // Section name, for verification
                if ($this->name != $match[1]) {
                    break;
                }
            } elseif (preg_match('/^[;#]/', $line) || empty($line)) {
                // Comment or empty line, add to the properties for output later
                if (!empty($line)) {
                    $this->properties[] = $line;
                }
            } elseif (preg_match('/^([A-z0-9 :*_-]+?)[ \t]*=[\t ]*(.*?)$/', $line, $match)) {
                // Key / Value Pair
                $propertyKey = $match[1];
                $propertyValue = $match[2];

                $isDuplicateProperty = isset($this->properties[$propertyKey]) &&
                    in_array($propertyKey, $this->allowedDuplicatePropertyKeys);

                if ($isDuplicateProperty) {
                    if (is_array($this->properties[$propertyKey])) {
                        array_push($this->properties[$propertyKey], $propertyValue);
                    } else {
                        $this->properties[$propertyKey] = [$this->properties[$propertyKey], $propertyValue];
                    }
                } else {
                    $this->properties[$propertyKey] = $propertyValue;
                }
            } else {
                throw new Exception('Invalid line: "' . $line . '"');
            }
        }

        return true;
    }

    /**
     * Whether the given property key is set
     *
     * @param string $propertyKey The name of the property
     * @return bool
     */
    public function isPropertySet(string $propertyKey): bool
    {
        $allProperties = $this->getAllProperties();
        return isset($allProperties[$propertyKey]);
    }

    /**
     * Get the value from the given property key
     *
     * @param string $propertyKey The name of the property
     * @return string | null
     */
    public function getProperty(string $propertyKey)
    {
        if ($this->isPropertySet($propertyKey)) {
            $allProperties = $this->getAllProperties();
            return $allProperties[$propertyKey];
        }

        // Returns null if no value is found
        return null;
    }

    /**
     * Get the full list of queued and current properties
     *
     * @return array
     */
    public function getAllProperties(): array
    {
        return array_merge($this->properties, $this->queuedProperties);
    }

    /**
     * Sets a given property with a given value
     *
     * @param string $propertyKey The name of the property
     * @param mixed $propertyValue The value of the property
     * @return bool
     */
    public function setProperty(string $propertyKey, $propertyValue): bool
    {
        $this->queuedProperties[$propertyKey] = $propertyValue;
        return true;
    }

    /**
     * Sets multiple properties via. associative array
     *
     * @param array $propertyArray An associative array of properties and values
     * @return bool
     */
    public function setProperties(array $propertyArray): bool
    {
        foreach ($propertyArray as $propertyKey => $propertyValue) {
            $this->setProperty($propertyKey, $propertyValue);
        }
        return true;
    }

    /**
     * Queues the removal of a property
     *
     * @param string $propertyKey
     * @return bool
     */
    public function removeProperty(string $propertyKey): bool
    {
        $allProperties = $this->getAllProperties();

        if (isset($allProperties[$propertyKey])) {
            $this->queuedProperties[$propertyKey] = null;
            return true;
        }

        return false;
    }

    /**
     * Sets the name of the section
     *
     * @param string $sectionName The new name of the section
     * @return bool
     */
    public function setName(string $sectionName): bool
    {
        $this->name = $sectionName;
        return (bool)$sectionName;
    }

    /**
     * Whether or not the section is valid. Checks includes and queued/stored properties.
     *
     * @return bool Whether or not the section is valid
     */
    public function isValid(): bool
    {
        if ($this->removalFlag) {
            // This section is not valid as it is about to be removed
            return false;
        }

        $allProperties = $this->getAllProperties();

        foreach ($allProperties as $propertyValue) {
            if ($propertyValue != null) {
                // A property has a value that is not null
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the name of the section
     *
     * @return string The name of the section
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the configuration string of the section
     *
     * @return string The configuration output
     */
    public function confOutput(): string
    {
        $outputString = '';
        if ($this->isValid() || $this->name == '#') {
            // Start with the section header
            if ($this->name === '#') {
                $outputString = '#';
            } elseif (!static::isComment($this->name)) {
                // It's not a comment, it's a section
                $outputString = '[' . $this->name . ']' . "\n";
            }

            foreach ($this->getAllProperties() as $propertyKey => $propertyValue) {
                if (is_numeric($propertyKey)) {
                    // A comment
                    $outputString .= $propertyValue;
                } else {
                    if (is_array($propertyValue)) {
                        foreach ($propertyValue as $singlePropertyValue) {
                            $outputString .= "\t" . $propertyKey . ' = ' . $singlePropertyValue;
                        }
                    } else {
                        if ($propertyValue != null) {
                            // Add the key/value stored
                            $outputString .= "\t" . $propertyKey . ' = ' . $propertyValue;
                        } else {
                            // Omit null values and no new lines
                            continue;
                        }
                    }
                }

                $outputString .= "\n";
            }

            // Newline at the end for separation
            $outputString .= "\n";
        }

        return $outputString;
    }

    /**
     * Marks the section for removal
     */
    public function markForRemoval()
    {
        foreach ($this->getAllProperties() as $propertyKey => $propertyValue) {
            $this->setProperty($propertyKey, null);
        }

        $this->removalFlag = true;
    }
}
