<?php
namespace Datto\Virtualization\Hypervisor\Config;

/**
 * VmSettings for Hyper-V Hypervisor
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class HvVmSettings extends AbstractVmSettings
{
    protected function loadDefaults(): void
    {
        $this
            ->setStorageController('auto')
            ->setNetworkMode('NONE');
    }

    protected function getType(): string
    {
        return 'hv';
    }

    public function getSupportedStorageControllers(): array
    {
        return ['ide', 'auto'];
    }
}
