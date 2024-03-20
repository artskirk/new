<?php

namespace Datto\Backup\Transport;

/**
 * Interface for backup transports.
 * Backup transports are used during the transfer of data from an agent to the device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
abstract class BackupTransport
{
    /**
     * Initialize the backup transport
     *
     * @param array $imageLoopsOrFiles
     * @param array $checksumFiles
     * @param array $allVolumes
     */
    abstract public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes);

    /**
     * Return the qualified name
     *
     * @return string
     */
    abstract public function getQualifiedName(): string;

    /**
     * Return the port number
     *
     * @return int
     */
    abstract public function getPort(): int;

    /**
     * Get the volume parameters.
     *
     * @return array
     */
    abstract public function getVolumeParameters(): array;

    /**
     * Get the api parameters
     *
     * @return array
     */
    abstract public function getApiParameters(): array;

    /**
     * Remove any existing artifacts
     */
    abstract public function cleanup();

    /**
     * Get the destination host to use for the transport.
     *
     * @param string $ipAddress This is the IP address of the device that will be used as the target for the backup.
     * @param bool $verifyCertificate This is a boolean that will be set to true if the certificate should be verified.
     * @return string This is the hostname (of the device) that should be used for the backup.
     */
    public function getDestinationHost(string $ipAddress, bool &$verifyCertificate): string
    {
        $verifyCertificate = false;
        return $ipAddress;
    }
}
