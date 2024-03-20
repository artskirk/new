<?php

namespace Datto\Service\Metrics;

use Datto\Common\Utility\Filesystem;

class TelegrafConfigToggler
{
    private const AVAILABLE_CONF_FILE_FORMAT = '/etc/telegraf/telegraf.available.d/%s.conf';
    private const ENABLED_CONF_FILE_FORMAT = '/etc/telegraf/telegraf.d/%s.conf';

    private const MKDIR_MODE = 0777;

    private Filesystem $filesystem;

    public function __construct(
        Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
    }

    /**
     * Enables an available Telegraf config file by symlinking it into the active configs directory.
     *
     * @param string $configName
     * @throws TelegrafConfigException when file already exists, but is not a symlink
     */
    public function enable(string $configName): void
    {
        $availableFile = sprintf(self::AVAILABLE_CONF_FILE_FORMAT, $configName);
        $enabledFile = sprintf(self::ENABLED_CONF_FILE_FORMAT, $configName);

        $exists = $this->filesystem->exists($enabledFile);
        $isLink = $this->filesystem->isLink($enabledFile);

        if ($exists && !$isLink) {
            throw TelegrafConfigException::forExistingNonSymlinkFile($configName);
        }

        if ($isLink) {
            return;
        }

        $this->filesystem->mkdirIfNotExists(dirname($enabledFile), true, self::MKDIR_MODE);
        $result = $this->filesystem->symlink($availableFile, $enabledFile);

        if (!$result) {
            throw TelegrafConfigException::forFailureToCreateSymlink($configName);
        }
    }

    /**
     * Disables an active Telegraf config file by removing its symlink from the active configs directory.
     *
     * @param string $configName
     * @throws TelegrafConfigException when the file can't be deleted
     */
    public function disable(string $configName): void
    {
        $enabledFile = sprintf(self::ENABLED_CONF_FILE_FORMAT, $configName);

        $exists = $this->filesystem->exists($enabledFile);
        $isLink = $this->filesystem->isLink($enabledFile);

        if ($exists && !$isLink) {
            throw TelegrafConfigException::forExistingNonSymlinkFile($configName);
        }

        if (!$exists) {
            return;
        }

        $result = $this->filesystem->unlink($enabledFile);

        if (!$result) {
            throw TelegrafConfigException::forFailureToDeleteFile($configName);
        }
    }
}
