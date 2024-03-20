<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZpoolService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Access local configuration settings.
 *
 * IMPORTANT NOTE:
 * There is also a bash reimplementation of these functions in the
 * datto-codebase-core file "/files/datto/scripts/config_utils".
 * It is extremely important that those functions be maintained to reflect
 * any future changes made to the functions in this implementation.
 * See also:  https://kaseya.atlassian.net/wiki/spaces/DEV/pages/592077667/Siris+Key+File+Function+Get+and+Set+Result+Values
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class LocalConfig extends FileConfig
{
    const BASE_LOCAL_CONFIG_PATH = '/datto/config/local';
    const BASE_HOME_CONFIG_PATH = '/home/_config';

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        parent::__construct(self::BASE_LOCAL_CONFIG_PATH, $filesystem ?: new Filesystem(new ProcessFactory()));
    }

    /**
     * Read the value from a key file.
     * Implements the legacy config_get() function.
     *
     * @deprecated Use get() or getRaw() instead.
     * @param string $key
     * @param bool $default
     * @return string|mixed Returns one of the following:
     *    $default if the file does not exist or an error occurred;
     *    TRUE if file exists and is empty;
     *    FALSE if the file contains only the string "false";
     *    contents of the file.
     */
    public function legacyConfigGet($key, $default = false)
    {
        $value = $this->getRaw($key);
        if ($value === false) {
            $value = $default;
        } else {
            if ($value == '') {
                $value = true;
            } elseif ($value == 'false') {
                $value = false;
            }
        }
        return $value;
    }

    /**
     * Migrate the "/home/_config" directory to a symbolic link.
     *
     * @param DeviceLoggerInterface|null $deviceLogger
     * @param ZpoolService|null $zpoolService
     * @return string Informational message describing what was done.
     */
    public function migrate($deviceLogger = null, $zpoolService = null)
    {
        $logger = $deviceLogger ?: LoggerFactory::getDeviceLogger();
        $zpoolService = $zpoolService ?: new ZpoolService();

        if (!$zpoolService->isImported(ZpoolService::HOMEPOOL)) {
            $message = ZpoolService::HOMEPOOL . ' is not imported. No action taken.';
            $logger->debug("LCF0004 $message");
        } elseif ($this->filesystem->isLink(self::BASE_HOME_CONFIG_PATH)) {
            $message = self::BASE_HOME_CONFIG_PATH . ' is already a symbolic link.  No action taken.';
            $logger->debug("LCF0001 $message");
        } else {
            if ($this->filesystem->exists(self::BASE_HOME_CONFIG_PATH)) {
                $this->filesystem->unlinkDir(self::BASE_HOME_CONFIG_PATH);
            }
            if ($this->filesystem->symlink(self::BASE_LOCAL_CONFIG_PATH, self::BASE_HOME_CONFIG_PATH)) {
                $message = self::BASE_HOME_CONFIG_PATH . ' has been converted to a symbolic link.';
                $logger->info('LCF0002 ' . self::BASE_HOME_CONFIG_PATH . ' has been converted to a symbolic link.');
            } else {
                $message = 'Failed to create symbolic link ' . self::BASE_HOME_CONFIG_PATH . '.';
                $logger->error('LCF0003 Failed to create symbolic link ' . self::BASE_HOME_CONFIG_PATH . '.');
                throw new \Exception($message);
            }
        }
        return $message;
    }
}
