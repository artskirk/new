<?php

namespace Datto\App\Security;

use Exception;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Represents a user of the system.
 *
 * This uses is decoupled from the WebUser that we provide
 * in Datto\User\WebUser, to not mix Symfony with the application
 * logic code.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class User implements UserInterface
{
    private string $username;
    /** @var string[] */
    private array $roles;

    public function __construct(string $username, array $roles)
    {
        $this->username = $username;
        $this->roles = $roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * {@inheritdoc}
     * @deprecated since Symfony 5.3, use getUserIdentifier() instead
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials(): void
    {
        // Never used.
    }

    /**
     * Not used.
     *
     * {@inheritdoc}
     */
    public function getPassword(): ?string
    {
        throw new Exception('Illegal call. This should not happen.');
    }

    /**
     * Not used.
     *
     * {@inheritdoc}
     */
    public function getSalt(): ?string
    {
        throw new Exception('Illegal call. This should not happen.');
    }
}
