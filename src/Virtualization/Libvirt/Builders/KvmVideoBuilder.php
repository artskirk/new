<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Utility\ByteUnit;
use Datto\Util\OsFamily;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;

/**
 * Video builder for KVM Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class KvmVideoBuilder extends BaseVmDefinitionBuilder
{
    const DEFAULT_VRAM_MIB = 64;
    const SMALL_VRAM_MIB = 16;

    public function build(VmDefinition $vmDefinition)
    {
        $guestOs = $this->getContext()->getGuestOs();
        $vmSettings = $this->getContext()->getVmSettings();

        $vmVideo = new VmVideoDefinition();
        $vmVideo->setModel($vmVideo::MODEL_VGA);
        $vmVideo->setVramKib(ByteUnit::MIB()->toKiB(self::DEFAULT_VRAM_MIB));

        // Update: After removing RDP support from the device, we are still maintaining these restrictions, because
        // we don't want to have to test all of the other combinations of graphics models and OS versions

        // Windows 2008/Vista
        // https://en.wikipedia.org/wiki/Comparison_of_Microsoft_Windows_versions#Windows_NT
        // To allow RDP connections to broken Windows agents we need to keep the video mode as CIRRUS
        $isWindowsWithBrokenVga = $guestOs->getOsFamily() === OsFamily::WINDOWS()
            && version_compare($guestOs->getVersion(), '6.0', '>=')
            && version_compare($guestOs->getVersion(), '6.1', '<');

        if ($isWindowsWithBrokenVga || $vmSettings->getVideoController() === VmVideoDefinition::MODEL_CIRRUS) {
            $vmVideo->setModel(VmVideoDefinition::MODEL_CIRRUS);
            $vmVideo->setVramKib(ByteUnit::MIB()->toKiB(self::SMALL_VRAM_MIB)); // CIRRUS displays use 16
        }

        $vmDefinition->getVideo()->append($vmVideo);
    }
}
