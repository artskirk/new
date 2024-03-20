<?php

namespace Datto\Config;

use Datto\Common\Utility\Filesystem;
use Datto\Utility\Systemd\Systemctl;

/**
 * Set rly tracker hosts for the device and restart the service.
 * Tracker discovery is disabled when hosts are set since they should mutually exclusive.
 */
class RlyConfig
{
    const RLY_SERVICE_CONF_PATH = '/etc/rly/client.conf';
    const RLY_CLIENT_SERVICE = 'rly-client.service';

    private Filesystem $filesystem;
    private Systemctl $systemctl;

    public function __construct(
        Filesystem $filesystem,
        Systemctl $systemctl
    ) {
        $this->filesystem = $filesystem;
        $this->systemctl = $systemctl;
    }

    public function setTrackerHosts(string $trackerHosts)
    {
        $rlyServiceContents = $this->filesystem->fileGetContents(self::RLY_SERVICE_CONF_PATH);
        // enable tracker hosts
        $trackerHostsRegex = '/^# TrackerHosts$/m';
        preg_match($trackerHostsRegex, $rlyServiceContents, $trackerHostMatches);
        if (count($trackerHostMatches) === 0) {
            // already enabled, replace existing tracker hosts
            $trackerHostsRegex = '/^TrackerHosts\s.*$/m';
        }

        $rlyServiceContents = preg_replace(
            $trackerHostsRegex,
            'TrackerHosts ' . $trackerHosts,
            $rlyServiceContents
        );
        $this->disableTrackerDiscovery($rlyServiceContents);
        $this->filesystem->filePutContents(self::RLY_SERVICE_CONF_PATH, $rlyServiceContents);
        $this->restartRlyClient();
    }

    private function disableTrackerDiscovery(&$fileContents)
    {
        preg_match('/^TrackerDiscovery\s.*$/m', $fileContents, $matches);
        $fileContents = preg_replace(
            '/^TrackerDiscovery\s.*$/m',
            '# ' . $matches[0],
            $fileContents
        );
    }

    private function restartRlyClient()
    {
        if ($this->systemctl->isActive(self::RLY_CLIENT_SERVICE)) {
            $this->systemctl->restart(self::RLY_CLIENT_SERVICE);
        }
    }
}
