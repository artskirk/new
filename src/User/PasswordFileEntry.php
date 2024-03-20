<?php

namespace Datto\User;

/**
 * Represents a single line in the "/etc/passwd" file.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class PasswordFileEntry
{
    /** @var string */
    private $line;

    /** @var string[] */
    private $parts;

    /**
     * @param string $line A single line of an "/etc/passwd" file.
     */
    public function __construct(string $line)
    {
        $this->line = rtrim($line, PHP_EOL);
        $this->parts = explode(':', $this->line);
    }

    /**
     * Determines if this user represents a "normal" user.
     * Normal users are non-system accounts that can be migrated between devices.
     *
     * @return bool
     */
    public function isNormalUser(): bool
    {
        return strlen($this->getName()) > 0 && $this->getUid() >= 1000 && $this->getGid() == 100;
    }

    /**
     * Gets the file line with the trailing newline.
     *
     * @return string
     */
    public function getLineWithEol(): string
    {
        return $this->line . PHP_EOL;
    }

    /**
     * Gets the user name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->parts[0] ?? '';
    }

    /**
     * Gets the user ID.
     *
     * @return int
     */
    public function getUid(): int
    {
        return (int)($this->parts[2] ?? 0);
    }

    /**
     * Gets the group ID.
     *
     * @return int
     */
    public function getGid(): int
    {
        return (int)($this->parts[3] ?? 0);
    }
}
