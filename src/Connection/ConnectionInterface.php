<?php
/**
 * ConnectionInterface.php
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @copyright 2015 Datto Inc
 */

namespace Datto\Connection;

/**
 * A generic interface for implementing hypervisor connections.
 *
 * A connection is basically a key-value store that holds various details
 * necessary to build a working connection to the hypervisor.
 *
 */
interface ConnectionInterface
{
    /**
     * Gets the hypervisor connection type.
     *
     * @return ConnectionType
     */
    public function getType();

    /**
     * Sets the hypervisor connection type.
     *
     * @param ConnectionType $connectionType
     *
     * @return self
     */
    public function setType(ConnectionType $connectionType);

    /**
     * Get the connection name.
     *
     * @return string
     */
    public function getName();

    /**
     * Sets the connection name.
     *
     * @param string $name
     *
     * @return self
     */
    public function setName($name);

    /**
     * Gets the connection credentials (if any).
     *
     * @return array|null
     *  A credential array or null if none. The array format depends on concrete
     *  class implementation.
     */
    public function getCredentials();

    /**
     * Whether the hypervisor connection is for virtualizing via libvirt.
     *
     * @return bool
     */
    public function isLibvirt();

    /**
     * Checks whether there's suficcient data to create a connection.
     *
     * This method checks if the required minimum data is present and does not
     * not validate the data itself, e.g. whether credentials are valid etc.
     * One can think of it as a "schema" or data integrity type of validation.
     *
     * @return bool
     */
    public function isValid();

    /**
     * Whether this is considered a 'primary' connection.
     *
     * Since there may be more than one connection of the same type defined
     * by the user, a primary connection will be always preferred in cases
     * where no specific connection name was request at virtualization time,
     * e.g. during VM screenshot verification which is an automated and
     * a non-interactive process.
     *
     * @return bool
     */
    public function isPrimary();

    /**
     * Determines if this connection was used to pair an agentless system
     *
     * @param string $searchPath the config path to search for connection information,
     *        for example: /datto/config/keys/*.esxInfo
     * @return int number of associated systems
     */
    public function isUsedForBackup($searchPath);

    /**
     * Checks if this connection can support screenshotting.
     *
     * Although most hypervisors do, there are hypervisors that while they have
     * an API to do so, it's so horrible (slow) that it's not feasible to use
     * it in practice, e.g. Hyper-V 2008 & 2012 (non-R2).
     *
     * @return bool
     */
    public function supportsScreenshots();
}
