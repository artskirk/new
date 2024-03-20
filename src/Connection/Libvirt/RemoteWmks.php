<?php

namespace Datto\Connection\Libvirt;

use Datto\Restore\Virtualization\ConsoleType;
use RuntimeException;

class RemoteWmks extends AbstractRemoteConsoleInfo
{
    /** @var string */
    private $ticket;

    /** @var string */
    private $thumbprint;

    /** @var string */
    private $cfgFile;

    public function __construct(
        string $host,
        ?int $port,
        string $ticket,
        string $thumbprint,
        string $cfgFile
    ) {
        parent::__construct($host, $port);

        $this->ticket = $ticket;
        $this->thumbprint = $thumbprint;
        $this->cfgFile = $cfgFile;
    }

    public function getType(): string
    {
        return ConsoleType::WMKS;
    }

    public function getValues(): array
    {
        return [
            'ticket' => $this->ticket,
            'host' => $this->getHost(),
            'port' => $this->getPort(),
            'thumbprint' => $this->thumbprint,
            'cfgFile' => $this->cfgFile,
        ];
    }

    public function setExtra(string $key, $value)
    {
        switch ($key) {
            case 'ticket':
                $this->ticket = $value;
                break;
            case 'thumbprint':
                $this->thumbprint = $value;
                break;
            case 'cfgFile':
                $this->cfgFile = $value;
                break;
            default:
                throw new RuntimeException('Invalid extra value for WMKS connection');
        }
    }
}
