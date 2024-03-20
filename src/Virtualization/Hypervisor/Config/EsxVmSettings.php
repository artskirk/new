<?php
namespace Datto\Virtualization\Hypervisor\Config;

use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;

/**
 * VmSettings for Esx Hypervisor
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxVmSettings extends AbstractVmSettings
{
    protected function loadDefaults(): void
    {
        $this
            ->setNetworkController('e1000e')
            ->setStorageController('lsisas1068')
            ->setNetworkMode('NONE') // we can't guess the network name.
            ->setVideoController(VmVideoDefinition::MODEL_VMWARE_VGA);
    }

    /**
     * {@inheritdoc}
     */
    protected function getType(): string
    {
        return 'esx';
    }
    
    /**
     * {@inheritdoc}
     *
     * @link https://kb.vmware.com/selfservice/microsites/search.do?language=en_US&cmd=displayKC&externalId=1001805
     */
    public function getSupportedNetworkControllers(): array
    {
        return ['vlance', 'e1000', 'e1000e', 'vmxnet', 'vmxnet2', 'vmxnet3'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedStorageControllers(): array
    {
        return ['ide', 'buslogic', 'lsilogic', 'lsisas1068', 'vmpvscsi'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedVideoControllers(): array
    {
        return [VmVideoDefinition::MODEL_VMWARE_VGA];
    }
}
