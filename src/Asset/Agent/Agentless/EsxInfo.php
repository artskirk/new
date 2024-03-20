<?php

namespace Datto\Asset\Agent\Agentless;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class EsxInfo
{
    const KEY_NAME = 'esxInfo';

    /** @var string */
    private $name;

    /** @var string */
    private $moRef;

    /** @var int */
    private $totalBytesCopied;

    /** @var array */
    private $vmdkInfo;

    /** @var string */
    private $vmxFile;

    /** @var string */
    private $connectionName;

    /**
     * @param string $name
     * @param string $moRef
     * @param int $totalBytesCopied
     * @param array $vmdkInfo
     * @param string $vmxFile
     * @param string $connectionName
     */
    public function __construct(
        string $name,
        string $moRef,
        int $totalBytesCopied,
        array $vmdkInfo,
        string $vmxFile,
        string $connectionName
    ) {
        $this->name = $name;
        $this->moRef = $moRef;
        $this->totalBytesCopied = $totalBytesCopied;
        $this->vmdkInfo = $vmdkInfo;
        $this->vmxFile = $vmxFile;
        $this->connectionName = $connectionName;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getMoRef(): string
    {
        return $this->moRef;
    }

    /**
     * @return int
     */
    public function getTotalBytesCopied(): int
    {
        return $this->totalBytesCopied;
    }

    /**
     * @return string
     */
    public function getVmxFile(): string
    {
        return $this->vmxFile;
    }

    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @return array
     */
    public function getVmdkInfo(): array
    {
        // todo make into data object
        return $this->vmdkInfo;
    }
}
