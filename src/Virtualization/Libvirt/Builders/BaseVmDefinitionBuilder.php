<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Util\OsFamily;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\VmDefinitionContext;

/**
 * Base class for contributing to build of a VmDefinition.
 *
 * Implementing classes should limit their scope to building a single aspect of a VmDefinition.
 *
 * It is preferred to keep Hypervisor specific logic in separate builder classes, and let VmDefinitionFactory
 * select which builder classes to use.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class BaseVmDefinitionBuilder
{
    /** @var VmDefinitionContext */
    private $context;

    /**
     * @param VmDefinitionContext $context
     */
    public function __construct(VmDefinitionContext $context)
    {
        $this->context = $context;
    }

    /**
     * @return VmDefinitionContext
     */
    protected function getContext(): VmDefinitionContext
    {
        return $this->context;
    }

    /**
     * Modify the VmDefinition state using context
     *
     * @param VmDefinition $vmDefinition
     */
    abstract public function build(VmDefinition $vmDefinition);

    /**
     * @return bool true if the guest OS is Windows Server 2003 or earlier
     */
    protected function isLegacyWindowsOs(): bool
    {
        // Windows 2003 or older.
        $guestOs = $this->getContext()->getGuestOs();
        return $guestOs->getOsFamily() === OsFamily::WINDOWS()
            && version_compare($guestOs->getVersion(), '5.3', '<');
    }
}
