<?php

namespace Datto\Verification;

use Datto\Core\Configuration\ConfigRecordInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ScreenshotOverride implements ConfigRecordInterface
{
    /** @var int|null */
    private $overrideCpuCores;

    /** @var int|null */
    private $overrideRamInMiB;

    /**
     * @param int $overrideCpuCores
     * @param int $overrideRamInMiB
     */
    public function __construct(int $overrideCpuCores = null, int $overrideRamInMiB = null)
    {
        $this->overrideCpuCores = $overrideCpuCores;
        $this->overrideRamInMiB = $overrideRamInMiB;
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return 'screenshotOverride';
    }

    /**
     * Deserialize the raw file contents into this config record instance
     *
     * @param string $raw key file contents
     */
    public function unserialize(string $raw)
    {
        $vals = unserialize($raw, ['allowed_classes' => false]);

        $this->overrideCpuCores = $vals['cpus'] ?? null;
        $this->overrideRamInMiB = $vals['ram'] ?? null;
    }

    /**
     * Serialize the config record for persistence to a key file
     *
     * @return string
     */
    public function serialize(): string
    {
        return serialize([
            'cpus' => $this->overrideCpuCores,
            'ram' => $this->overrideRamInMiB
        ]);
    }

    /**
     * @return int|null
     */
    public function getOverrideCpuCores()
    {
        return $this->overrideCpuCores;
    }

    /**
     * @return int|null
     */
    public function getOverrideRamInMiB()
    {
        return $this->overrideRamInMiB;
    }
}
