<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\VmDefinition;

/**
 * CPU builder for KVM Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class KvmCpuBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmDefinition->setCpuMode(VmDefinition::CPU_MODE_CUSTOM);

        // for "legacy" settings use the most compatible CPU we know of and don't
        // bother with auto-detection
        if ($this->getContext()->isModernEnvironment() === false) {
            // this is really kvm64+lahf_lm feature
            $vmDefinition->setCpuModel('cpu64-rhel6');
            return;
        }

        // this is the model as reported by Linux
        // ex: Intel(R) Core(TM) i3-5010U CPU @ 2.10GHz
        $modelName = $this->getContext()->getVmHostProperties()->getLocalCpuModel();

        /* Haswell based Pentiums are castrated Haswells that need special treatment
         * that is, libvirt would 'categorize' those as Nehalem because that's the
         * lowest common denominator when feature-testing, such 'downgrade' however,
         * causes BSOD on some Windows Server installations. To address this, set
         * model to Haswell-noTSX, and disable fetures it doesn't support
         */
        if (preg_match('/Intel.*Pentium.*G3\d{3}/i', $modelName)) {
            $vmDefinition->setCpuModel('Haswell-noTSX');
            $vmDefinition->setCpuFeatures(
                [
                    'aes' => false,
                    'avx' => false,
                    'avx2' => false,
                    'bmi1' => false,
                    'bmi2' => false,
                    'fma' => false,
                    'smep' => false,
                    'x2apic' => false,
                ]
            );
        } else {
            // this is the model as reported by libvirt
            // ex: Broadwell-noTSX-IBRS
            $vmDefinition->setCpuModel($this->getContext()->getVmHostProperties()->getLibvirtCpuModel());
        }
    }
}
