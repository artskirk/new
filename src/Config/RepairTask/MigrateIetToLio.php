<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\IscsiTargetException;
use Datto\Iscsi\IscsiTargetNotFoundException;
use Datto\Iscsi\UserType;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Migrates iscsi targets in /etc/iet/ietd.conf to LIO
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class MigrateIetToLio implements ConfigRepairTaskInterface
{
    const IETD_CONF_FILE = '/etc/iet/ietd.conf';
    const IETD_CONF_MIGRATED_SUFFIX = '.migrated';

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param IscsiTarget $iscsiTarget
     */
    public function __construct(DeviceLoggerInterface $logger, Filesystem $filesystem, IscsiTarget $iscsiTarget)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->iscsiTarget = $iscsiTarget;
    }

    /**
     * Execute the task
     * @return bool true if the task modified config, else false
     */
    public function run(): bool
    {
        if (!$this->filesystem->exists(self::IETD_CONF_FILE)) {
            return false;
        }

        $config = $this->filesystem->fileGetContents(self::IETD_CONF_FILE);

        $configLines = explode("\n", $config);
        $success = $this->migrate($configLines);

        // migration occurred successfully, rename file to prevent migrating again
        if ($success) {
            $this->filesystem->rename(
                self::IETD_CONF_FILE,
                self::IETD_CONF_FILE . self::IETD_CONF_MIGRATED_SUFFIX
            );
        }

        return true;
    }

    /**
     * Migrate the config from ietd.conf to LIO
     * @see http://manpages.ubuntu.com/manpages/xenial/man5/ietd.conf.5.html
     *
     * For global options, we are only concerned with 'Target'.
     * For target options, we are only concerned with the 'IncomingUser', 'OutgoingUser' and 'Lun' keys.
     * All others don't need to be migrated.
     *
     * @param string[] $configLines
     * @return bool True if migration completed without errors, otherwise false
     */
    private function migrate(array $configLines): bool
    {
        $configLines = array_map('trim', $configLines);

        // remove blank lines and comments
        $configLines = array_values(array_filter($configLines, function (string $line) {
            return $line !== '' && strpos($line, '#') !== 0;
        }));

        // Migrate the config
        // 'Target' config is last so skip over anything that doesn't match it. We don't need the other global config.
        $backstorePath = null;
        $currentTarget = null;
        $knownTargets = [];
        foreach ($configLines as $line) {
            try {
                if (preg_match('/^Target\s+(\S+)$/', $line, $matches)) {
                    // ex: Target iqn.2007-01.net.datto.dev.huevosrancheros:myShareName
                    // Per RFC-3722, underscores are not allowed in target names. Before LIO we used the less strict iet
                    // which allowed underscores. We must replace them here so targets continue to work.
                    $currentTarget = str_replace('_', '-', $matches[1]);
                    $knownTargets[] = $currentTarget;
                    $this->handleTarget($currentTarget);
                } elseif ($currentTarget && preg_match('/^(IncomingUser|OutgoingUser)\s+(\S+)\s+(\S+)$/', $line, $matches)) {
                    // ex: IncomingUser myUsername myPassword
                    $this->handleTargetUser($currentTarget, $matches[1], $matches[2], $matches[3]);
                } elseif ($currentTarget && preg_match('/^Lun\s+\d+\s+(.+)$/', $line, $matches)) {
                    // ex: Lun 0 BlockSize=4096,Path=/dev/zvol/homePool/home/720c3b3840a64d19ab3725933836a01d,Type=blockio,IOMode=wt
                    $backstorePath = $matches[1];
                    $this->handleTargetLun($currentTarget, $backstorePath);
                }
            } catch (Throwable $e) {
                $this->logger->error('LIO0004 Error migrating line', ['line' => $line, 'exception' => $e]);
            }
        }

        // check for targets with zero associated LUNs and delete those targets
        $this->logger->info("LIO0005 Checking LUNs across known targets...");
        foreach ($knownTargets as $target) {
            try {
                $targetLuns = $this->iscsiTarget->listTargetLuns($target);
            } catch (IscsiTargetNotFoundException $e) {
                $this->logger->notice("$target does not exist, continuing");
                continue;
            }
            if (count($targetLuns) === 0) {
                $this->logger->error('LIO0006 Target has no LUNs, deleting the target to avoid issues', ['target' => $target]);
                try {
                    $this->iscsiTarget->deleteTarget($target);
                } catch (IscsiTargetNotFoundException $e) {
                    // target doesn't exist, no problems here
                }
            }
        }

        // try to save the changes
        try {
            $this->iscsiTarget->writeChanges();
        } catch (Throwable $e) {
            $this->logger->error('LIO0007 Error writing changes', ['exception' => $e]);
            // don't rename the configuration file if saving the changes fails
            return false;
        }

        return true;
    }

    /**
     * Handle the migration of the global option 'Target'.
     *
     * @param string $target
     */
    private function handleTarget(string $target)
    {
        $this->logger->info('LIO0001 Creating iSCSI target from ietd.conf', ['target' => $target]);
        $this->iscsiTarget->createTarget($target);
    }

    /**
     * Handle the migration of the target option 'IncomingUser' and 'OutgoingUser'.
     *
     * @param string $target
     * @param string $key
     * @param string $user
     * @param string $pass
     */
    private function handleTargetUser(string $target, string $key, string $user, string $pass)
    {
        $userType = $key === 'IncomingUser' ? UserType::INCOMING() : UserType::OUTGOING();

        $this->logger->info('LIO0002 Adding user to target', ['user' => $user, 'target' => $target]);
        $this->iscsiTarget->addTargetChapUser($target, $userType, $user, $pass);
    }

    /**
     * Handle the migration of the target option 'Lun'.
     *
     * @param string $target
     * @param string $lunParameters
     */
    private function handleTargetLun(string $target, string $lunParameters)
    {
        $parameters = $this->parseLunParameters(trim($lunParameters));

        if (!isset($parameters['Path'])) {
            throw new IscsiTargetException('Iscsi target lun is missing "Path" parameter');
        }

        $path = $parameters['Path'];
        $readOnly = ($parameters['IOMode'] ?? '') === 'ro';
        $writeBack = ($parameters['IOMode'] ?? '') === 'wb';
        $wwn = $parameters['ScsiSN'] ?? null;
        $backstoreAttributes = [];
        if (isset($parameters['BlockSize'])) {
            $backstoreAttributes[] = 'block_size=' . $parameters['BlockSize'];
        }

        $this->logger->info(
            'LIO0003 Adding lun to target',
            ['target' => $target, 'path' => $path, 'readonly' => $readOnly, 'writeBack' => $writeBack, 'wwn' => $wwn, 'backstoreAttributes' => $backstoreAttributes]
        );
        $this->iscsiTarget->addLun($target, $path, $readOnly, $writeBack, $wwn, $backstoreAttributes);
    }

    /**
     * Parse Lun parameters into an array.
     *
     * @param string
     * @return string[]
     */
    private function parseLunParameters(string $lunParameters): array
    {
        // Translate escaped quotes so they don't interfere with the next part
        $lunParameters = str_replace('\"', '&quot;', $lunParameters);

        // Replace quoted sections with placeholders
        $placeholders = [];
        if (preg_match_all('/"(.*?)"/', $lunParameters, $matches)) {
            $placeholders = $matches[0];
        }
        foreach ($placeholders as $i => $section) {
            $lunParameters = str_replace($section, "[quoted string $i]", $lunParameters);
        }

        $parametersList = explode(',', $lunParameters);
        $parameters = [];

        foreach ($parametersList as $parameter) {
            list($key, $value) = explode('=', $parameter, 2);

            // Swap the placeholders with their unquoted original values and add back in any escaped quotes
            foreach ($placeholders as $i => $section) {
                $value = str_replace("[quoted string $i]", trim($section, '"'), $value);
                $value = str_replace('&quot;', '"', $value);
            }
            $parameters[$key] = $value;
        }

        return $parameters;
    }
}
