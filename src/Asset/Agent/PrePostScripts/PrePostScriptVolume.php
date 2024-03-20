<?php
namespace Datto\Asset\Agent\PrePostScripts;

/**
 * A protected volume and its' list of pre/post scripts
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class PrePostScriptVolume
{
    /** @var string */
    private $volumeName;

    /** @var string */
    private $blockDevice;

    /** @var PrePostScript[] */
    private $scripts;

    /**
     * @param string $volumeName name of the volume
     * @param string $blockDevice
     * @param PrePostScript[] $scripts pre/post scripts
     */
    public function __construct($volumeName, $blockDevice, $scripts)
    {
        $this->volumeName = $volumeName;
        $this->blockDevice = $blockDevice;
        $this->scripts = is_array($scripts) ? $this->sortScripts($scripts) : array();
    }

    /**
     * @return string volume name
     */
    public function getVolumeName()
    {
        return $this->volumeName;
    }

    /**
     * @return string block device
     */
    public function getBlockDevice()
    {
        return $this->blockDevice;
    }

    /**
     * @return PrePostScript[] list of scripts
     */
    public function getScripts()
    {
        return $this->scripts;
    }

    /**
     * @return PrePostScript[] list of enabled scripts
     */
    public function getEnabledScripts()
    {
        $enabledScripts = array();

        foreach ($this->scripts as $name => $script) {
            if ($script->isEnabled()) {
                $enabledScripts[$name] = $script;
            }
        }

        return $enabledScripts;
    }

    /**
     * @param $scriptName name of the script to enable
     */
    public function enableScript($scriptName): void
    {
        $this->scripts[$scriptName]->setEnabled(true);
    }

    /**
     * @param $scriptName name of the script to disable
     */
    public function disableScript($scriptName): void
    {
        $this->scripts[$scriptName]->setEnabled(false);
    }

    /**
     * Copies some settings from another volume, overwriting this volume
     *
     * @param PrePostScriptVolume $from volume to copy settings from
     */
    public function copyFrom(PrePostScriptVolume $from): void
    {
        $this->volumeName = $from->getVolumeName();
        $this->blockDevice = $from->getBlockDevice();

        foreach ($from->getScripts() as $script) {
            $scriptName = $script->getName();
            if (isset($this->scripts[$scriptName])) {
                $this->scripts[$scriptName]->copyFrom($script);
            } else {
                $this->scripts[$scriptName] = $script;
            }
        }
        $this->scripts = $this->sortScripts($this->scripts);
    }

    private function sortScripts($scripts): array
    {
        usort(// alphabetical order
            $scripts,
            function (PrePostScript $scriptA, PrePostScript $scriptB) {
                return ($scriptA->getDisplayName() < $scriptB->getDisplayName());
            }
        );

        $namedScripts = array();
        foreach ($scripts as $script) {
            $namedScripts[$script->getName()] = $script;
        }

        return $namedScripts;
    }
}
