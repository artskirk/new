<?php

namespace Datto\Config\RepairTask;

use Datto\Afp\AfpVolumeManager;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Util\IniTranslator;
use Psr\Log\LoggerAwareInterface;

/**
 * Add UAM list to globals section of AFP conf to only allow supported UAMs
 *
 * @author Patrick Gillen <pgillen@datto.com>
 */
class AddUamListToAfpConf implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private Filesystem $filesystem;
    private IniTranslator $iniTranslator;

    public function __construct(
        Filesystem $filesystem,
        IniTranslator $iniTranslator
    ) {
        $this->filesystem = $filesystem;
        $this->iniTranslator = $iniTranslator;
    }

    /**
     * Execute the task
     * @return bool true if the task added the UAM list to the config, else false
     */
    public function run(): bool
    {
        if (!$this->filesystem->exists(AfpVolumeManager::AFP_CONF_FILE)) {
            return false;
        }

        return $this->addUamListToConfig();
    }

    /**
     * @return true if the UAM list was added to the configuration file successfully
     */
    private function addUamListToConfig() : bool
    {
        $afpConfArray = $this->filesystem->parseIniFile(AfpVolumeManager::AFP_CONF_FILE, true, INI_SCANNER_TYPED);
        if (!isset($afpConfArray['Global'][AfpVolumeManager::UAM_LIST_KEY])
            || $afpConfArray['Global'][AfpVolumeManager::UAM_LIST_KEY] != AfpVolumeManager::UAM_LIST_VALUES) {
            $afpConfArray['Global'][AfpVolumeManager::UAM_LIST_KEY] = AfpVolumeManager::UAM_LIST_VALUES;
            return $this->filesystem->filePutContents(
                AfpVolumeManager::AFP_CONF_FILE,
                $this->iniTranslator->stringify($afpConfArray)
            );
        }
        return false;
    }
}
