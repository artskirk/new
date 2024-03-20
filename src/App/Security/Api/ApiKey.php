<?php

namespace Datto\App\Security\Api;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ApiKey
{
    /** @var string */
    private $user;

    /** @var string */
    private $apiKey;

    /** @var array */
    private $roles;

    /**
     * @param string $user
     * @param string $apiKey
     * @param array $roles
     */
    public function __construct(string $user, string $apiKey, array $roles)
    {
        $this->user = $user;
        $this->apiKey = $apiKey;
        $this->roles = $roles;
    }

    /**
     * @return string
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->roles;
    }
}
