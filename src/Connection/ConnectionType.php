<?php
/**
 * ConnectionType.php
 * @author Nate Levesque <nlevesque@datto.com>
 * @copyright 2015 Datto Inc
 */

namespace Datto\Connection;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of connection types
 *
 * @method static ConnectionType LIBVIRT_ESX()
 * @method static ConnectionType LIBVIRT_KVM()
 * @method static ConnectionType LIBVIRT_HV()
 * @method static ConnectionType AWS()
 */
class ConnectionType extends AbstractEnumeration
{
    const LIBVIRT_ESX = "esx";
    const LIBVIRT_KVM = "kvm";
    const LIBVIRT_HV = "hyperv";
    const AWS = "aws";

    /**
     * Return a display friendly string for the ConnectionType
     *
     * @return string
     */
    public function toDisplayName()
    {
        switch ($this->value()) {
            case self::LIBVIRT_HV:
                $displayName = 'Hyper-V';
                break;
            default:
                $displayName =  strtoupper($this->value());
        }

        return $displayName;
    }
}
