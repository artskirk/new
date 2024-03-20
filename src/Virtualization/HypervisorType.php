<?php

namespace Datto\Virtualization;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Hypervisor Type
 *
 * @author Jason Lodice <JLodice@datto.com
 *
 * @method static HypervisorType VMWARE()
 * @method static HypervisorType HYPER_V()
 * @method static HypervisorType KVM()
 */
class HypervisorType extends AbstractEnumeration
{
    const VMWARE = 'vmware';
    const HYPER_V = 'hyperv';
    const KVM = 'kvm';
}
