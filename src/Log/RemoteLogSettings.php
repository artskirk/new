<?php

namespace Datto\Log;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Read and update the remote logging configuration.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class RemoteLogSettings
{
    const CONFIG_PATH = "/etc/rsyslog.d/12-datto-audit.conf";

    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    public function __construct(
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Get the server addresses and ports configured for remote logging.
     *
     * @return array of the form [ ['address' => '1.2.3.4', 'port' => 123], ... ]
     */
    public function getServers(): array
    {
        $serverAddresses = [];

        if (!$this->filesystem->exists(static::CONFIG_PATH)) {
            return [];
        }

        $lines = $this->filesystem->file(static::CONFIG_PATH);
        foreach ($lines as $remoteConfig) {
            $hasMatch = preg_match('/@@([^:]+):(\d+)$/', $remoteConfig, $remoteInfo);
            if ($hasMatch === 1) {
                $serverAddresses[] = [
                    "address" => $remoteInfo[1],
                    "port"    => $remoteInfo[2],
                ];
            }
        }

        return $serverAddresses;
    }

    /**
     * Update the remote logging server list.
     *
     * @param array $remoteServers Each element is an associative array with keys "address" and "port"
     */
    public function updateServers(array $remoteServers)
    {
        $config = '';

        foreach ($remoteServers as $remote) {
            $address = trim($remote['address'] ?? '');
            if ($address === '' || !isset($remote['port'])) {
                throw new Exception("Missing remote server address or port");
            }
            $port = (int)$remote['port'];
            $config .= ":syslogtag,startswith,\"datto.audit\" @@$address:$port" . PHP_EOL;
        }

        $this->filesystem->filePutContents(static::CONFIG_PATH, $config);

        $process = $this->processFactory->get(['service', 'rsyslog', 'restart']);
        $process->run();
    }
}
