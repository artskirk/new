<?php

namespace Datto\User;

/**
 * Represents a single line in the "/etc/shadow" file.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class WebAccessFileEntry
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
        return $this->parts[0] ?? '';
    }

    /**
     * Gets the user name.
     *
     * @return string
     */
    public function getPwHash(): string
    {
        return $this->parts[1] ?? '';
    }
}
