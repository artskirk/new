<?php

namespace Datto\Afp;

/**
 * Represents the configuration for an AFP share
 * @author Peter Geer <pgeer@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class AfpShare
{
    private string $sharePath;

    private string $shareName;

    private string $cnidScheme;

    private bool $allowTimeMachine;

    private string $allowedUsers;

    public function __construct(
        string $sharePath,
        string $shareName,
        string $cnidScheme,
        bool $allowTimeMachine,
        string $allowedUsers
    ) {
        $this->sharePath = trim($sharePath);
        $this->shareName = trim($shareName);
        $this->cnidScheme = trim($cnidScheme);
        $this->allowTimeMachine = $allowTimeMachine;
        $this->allowedUsers = trim($allowedUsers);
    }

    /**
     * Factory method to create a share from an ini style config file section
     *
     * @param string $shareName - usually the section name
     * @param array $sectionContents
     * @return AfpShare
     */
    public static function fromConfigSection(string $shareName, array $sectionContents): AfpShare
    {
        $sharePath = $sectionContents['path'] ?? '';
        $cnidScheme = $sectionContents['cnid scheme'] ?? '';
        $allowTimeMachine = $sectionContents['time machine'] ?? false;
        $allowedUsers = $sectionContents['valid users'] ?? '';

        return new AfpShare($sharePath, $shareName, $cnidScheme, $allowTimeMachine, $allowedUsers);
    }

    /**
     * Return the share configuration as config file line
     *
     * @return string
     */
    public function outputString(): string
    {
        $timeMachineConfig = $this->getAllowTimeMachine() ? 'yes' : 'no';

        $output = "[{$this->getShareName()}]\n" .
            "path = {$this->getSharePath()}\n" .
            "cnid scheme = {$this->getCnidScheme()}\n" .
            "unix priv = yes\n" .
            "time machine = $timeMachineConfig" .
            (strlen($this->getAllowedUsers()) > 0 ? "\nvalid users = {$this->getAllowedUsers()}" : '');

        return $output;
    }

    public function setAllowedUsers(string $allowedUsers): void
    {
        $this->allowedUsers = trim($allowedUsers);
    }

    public function getSharePath(): string
    {
        return $this->sharePath;
    }

    public function getShareName(): string
    {
        return $this->shareName;
    }

    public function getCnidScheme(): string
    {
        return $this->cnidScheme;
    }

    public function getAllowTimeMachine(): bool
    {
        return $this->allowTimeMachine;
    }

    public function getAllowedUsers(): string
    {
        return $this->allowedUsers;
    }
}
