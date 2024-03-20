<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;
use Datto\Virtualization\Libvirt\Domain\VmControllerDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;

/**
 * Storage controller builder
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class StorageControllersBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmSettings = $this->getContext()->getVmSettings();
        $model =  $vmSettings->getStorageController(AbstractVmSettings::FORMAT_LIBVIRT);

        if ($vmSettings->isScsi()) {
            $type = VmControllerDefinition::TYPE_SCSI;
        } elseif ($vmSettings->isIde() && $model != 'ide') { // 'ide' means hypervisor-default, so not a valid model.
            $type = VmControllerDefinition::TYPE_IDE;
        }

        if (isset($type)) {
            $controllers = $vmDefinition->getControllers();
            $index = $controllers->count();

            // currently we support only one controller at a time
            $controller = new VmControllerDefinition();
            $controller->setIndex($index);
            $controller->setType($type);
            $controller->setModel($model);
            $controllers->append($controller);
        }
    }
}
