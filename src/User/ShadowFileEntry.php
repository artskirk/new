<?php

namespace Datto\User;

use Exception;

/**
 * Represents a single line in the "/etc/shadow" file.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class ShadowFileEntry
{
    /** @var string */
    private $line;

    /** @var string[] */
    private $parts;

    /**
     * @param string $line A single line of an "/etc/shadow" file.
     */
    public function __construct(string $line)
    {
        $this->line = rtrim($line, PHP_EOL);
        $this->parts = explode(':', $this->line);
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
        if (empty($this->parts[0])) {
            throw new Exception("Name field in the user shadow file is empty. The user shadow file is corrupt.");
        }
        return $this->parts[0];
    }

    /**
     * Gets the user name.
     *
     * @return string
     */
    public function getPasswordHash(): string
    {
        if (empty($this->parts[1])) {
            throw new Exception("Error password hash in the user shadow file for user {$this->parts[0]} is empty. The user shadow file is corrupt");
        }
        return $this->parts[1];
    }
}
