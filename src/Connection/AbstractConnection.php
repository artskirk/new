<?php
/**
 * AbstractConnection.php
 *
 * @author John Fury Christ <jchrist@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @copyright 2015 Datto Inc
 *
 */
namespace Datto\Connection;

/**
 * Class AbstractConnection
 *
 * @package Datto\Connection
 */
abstract class AbstractConnection implements ConnectionInterface
{
    /**
     * Contains the connection data
     * @var array $connection_data
     */
    protected $connectionData;

    /**
     * Connection name
     * @var string $name
     */
    protected $name;

    /**
     * Connection Type
     *
     * @var ConnectionType $connectionType
     */
    protected $connectionType;

    /**
     * @param ConnectionType $type
     * @param string $name
     */
    public function __construct(ConnectionType $type, $name = null)
    {
        $this->connectionData = array();
        $this->setName($name);
        $this->setType($type);
    }

    public function getType()
    {
        return $this->connectionType;
    }

    public function setType(ConnectionType $connectionType)
    {
        $this->connectionType = $connectionType;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = self::sanitizeFileName($name);

        return $this;
    }


    /**
     * Sets the configuration file key and value
     *
     * @param string $key
     * @param mixed|null $value
     *
     * @return self
     */
    protected function setKey($key, $value)
    {
        $this->connectionData[$key] = $value;

        return $this;
    }

    /**
     * Gets the configuration value for a given key
     *
     * @param string $key
     *  A key name of the backing store to get the value of.
     *
     * @return mixed|null $value
     *  If the key does not exist, returns NULL.
     */
    protected function getKey($key)
    {
        // avoid PHP notice of non-existing key.
        if (isset($this->connectionData[$key])) {
            return $this->connectionData[$key];
        } else {
            return null;
        }
    }

    public function getCredentials()
    {
        return null;
    }

    public function isPrimary()
    {
        return (bool) $this->getKey('isPrimary');
    }

    public function setIsPrimary($isPrimary)
    {
        $this->setKey('isPrimary', (bool) $isPrimary);

        return $this;
    }

    public function isUsedForBackup($searchPath)
    {
        $name = $this->getName();
        $count = 0;
        $infoFiles = glob($searchPath);
        foreach ($infoFiles as $infoFile) {
            $infoData = unserialize(file_get_contents($infoFile), ['allowed_classes' => false]);
            if ($infoData['connectionName'] === $name) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sanitizes a file name to escape invalid characters.
     *
     * @todo This should go to some sort of utility library when we have it so
     *       that it can be reused elsewhere.
     * @param string $name
     *
     * @return string
     */
    public static function sanitizeFileName($name)
    {
        $name = preg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '_', $name);
        $name = preg_replace("([\.]{2,})", '_', $name);

        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsScreenshots()
    {
        return true;
    }
}
