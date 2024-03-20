<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Utility\ByteUnit;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;

/**
 * Video builder for ESX Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxVideoBuilder extends BaseVmDefinitionBuilder
{
    const DEFAULT_VRAM_MIB = 16;

    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmVideo = new VmVideoDefinition();
        $vmVideo->setModel($vmVideo::MODEL_VMWARE_VGA);
        $vmVideo->setVramKib(ByteUnit::MIB()->toKiB(self::DEFAULT_VRAM_MIB));
        $vmDefinition->getVideo()->append($vmVideo);
    }
}
