<?php

namespace Datto\Config\RepairTask;

use Datto\Afp\AfpVolumeManager;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Migrates Afp Configuration for afpd V2 in AppleVolumes.default to afp.conf for V3
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class MigrateAfpdV2ToV3 implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const APPLEVOLUMES_DEFAULT_FILE = '/etc/netatalk/AppleVolumes.default';

    private Filesystem $filesystem;

    private AfpVolumeManager $afpVolumeManager;

    public function __construct(
        Filesystem $filesystem,
        AfpVolumeManager $afpVolumeManager
    ) {
        $this->filesystem = $filesystem;
        $this->afpVolumeManager = $afpVolumeManager;
    }

    /**
     * Execute the task
     * @return bool true if the task modified config, else false
     */
    public function run(): bool
    {
        if (!$this->filesystem->exists(self::APPLEVOLUMES_DEFAULT_FILE)) {
            return false;
        }

        $success = $this->migrateAfpdV2Config();

        // migration was fully successful, delete files to prevent migrating again
        // These files are available in previous config backups
        if ($success) {
            $this->filesystem->unlinkIfExists(self::APPLEVOLUMES_DEFAULT_FILE);
            $this->filesystem->unlinkIfExists('/etc/netatalk/AppleVolumes.system');
            $this->filesystem->unlinkIfExists('/etc/netatalk/afp_ldap.conf');
            $this->filesystem->unlinkIfExists('/etc/netatalk/afpd.conf');
            $this->filesystem->unlinkIfExists('/etc/netatalk/atalkd.conf');
            $this->filesystem->unlinkIfExists('/etc/netatalk/papd.conf');
        }

        return true;
    }

    /**
     * Migrate the config from AppleVolumes.default to afp.conf
     * @see http://netatalk.sourceforge.net/3.1/htmldocs/upgrade.html
     *
     * All other files don't need to be migrated.
     *
     * @return bool True if migration completed without errors, otherwise false
     */
    private function migrateAfpdV2Config(): bool
    {
        $success = true;
        $config = $this->filesystem->fileGetContents(self::APPLEVOLUMES_DEFAULT_FILE);

        $configLines = explode("\n", $config);
        // Migrate the config
        foreach ($configLines as $configLine) {
            $isComment = preg_match('/^\s*#/', $configLine);
            $isDefaultOptions = preg_match('/^:DEFAULT:/i', $configLine);
            $isAbsolutePath = preg_match('!^/!i', $configLine);

            if (!$isComment && !$isDefaultOptions && $isAbsolutePath) {
                $success = $this->addShareFromConfigLine($configLine) && $success;
            }
        }

        $success = $this->filesystem->rename('/etc/netatalk/afp_signature.conf', '/var/lib/netatalk/afp_signature.conf') && $success;
        $success = $this->filesystem->rename('/etc/netatalk/afp_voluuid.conf', '/var/lib/netatalk/afp_voluuid.conf') && $success;

        return $success;
    }

    /**
     * Creates and persists a share from an AppleVolumes.default config file line.
     */
    public function addShareFromConfigLine(string $line): bool
    {
        $updated = false;
        try {
            $shareString = trim($line);
            // Example line: /datto/mounts/test "test" cnidscheme:dbd options:usedots,upriv,tm allow:datto
            preg_match('/^(\/.*) "(.*)" cnidscheme:(.*) options:(.*) allow:(.*)/', $shareString, $shareArray);

            $sharePath = $shareArray[1] ?? '';
            $shareName = $shareArray[2] ?? '';
            // cnidScheme is unused, we default to dbd
            $cnidScheme = $shareArray[3] ?? '';
            $shareOptions = $shareArray[4] ?? '';
            $allowedUsers = $shareArray[5] ?? '';

            // usedots and upriv are enabled by default in V3
            $allowTimeMachine = array_key_exists('tm', array_flip(explode(',', $shareOptions)));
            // New format expects spaces as a delimiter
            $allowedUsers = str_replace(',', ' ', $allowedUsers);

            $this->afpVolumeManager->addShare($sharePath, $shareName, $allowTimeMachine, $allowedUsers);
            $updated = true;
        } catch (Throwable $e) {
            $this->logger->error(
                'AFP0004 Error migrating configuration line from AppleVolumes.default',
                ['line' => $line, 'message' => $e->getMessage()]
            );
        }

        return $updated;
    }
}
