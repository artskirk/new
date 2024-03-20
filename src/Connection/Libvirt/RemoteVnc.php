<?php

namespace Datto\Connection\Libvirt;

use Datto\Restore\Virtualization\ConsoleType;
use RuntimeException;

class RemoteVnc extends AbstractRemoteConsoleInfo
{
    /** @var string|null */
    private $password;

    /** @var string|null */
    private $token;

    public function __construct(
        string $host,
        ?int $port
    ) {
        parent::__construct($host, $port);
        $this->password = null;
        $this->token = null;
    }

    public function getType(): string
    {
        return ConsoleType::VNC;
    }

    public function getValues(): array
    {
        return [
            'token' => $this->token,
            'password' => $this->password,
        ];
    }

    public function setExtra(string $key, $value)
    {
        switch ($key) {
            case 'password':
                $this->password = $value;
                break;
            case 'token':
                $this->token = $value;
                break;
            default:
                throw new RuntimeException('Invalid extra value for VNC connection');
        }
    }
}
