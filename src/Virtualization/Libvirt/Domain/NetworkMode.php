<?php

namespace Datto\Virtualization\Libvirt\Domain;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @method static NetworkMode NAT()
 * @method static NetworkMode INTERNAL()
 * @method static NetworkMode BRIDGED()
 * @method static NetworkMode NONE()
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class NetworkMode extends AbstractEnumeration
{
    const NAT = 'NAT';
    const INTERNAL = 'INTERNAL';
    const BRIDGED = 'BRIDGED';
    const NONE = 'NONE';
}
