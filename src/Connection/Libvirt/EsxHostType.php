<?php

namespace Datto\Connection\Libvirt;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @method static EsxHostType STANDALONE()
 * @method static EsxHostType VCENTER()
 * @method static EsxHostType CLUSTER()
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxHostType extends AbstractEnumeration
{
    const STANDALONE = 'stand-alone';
    const VCENTER = 'vcenter-managed';
    const CLUSTER = 'cluster';
}
