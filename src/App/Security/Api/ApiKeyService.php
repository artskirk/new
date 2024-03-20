<?php

namespace Datto\App\Security\Api;

use Datto\Security\PasswordGenerator;
use Datto\User\Roles;
use Datto\User\ShadowUser;
use Datto\Common\Utility\Filesystem;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Manages API keys.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ApiKeyService
{
    const API_KEY_STORAGE_FORMAT = '/var/lib/datto/device/api/keys/%s';
    const API_KEY_LENGTH = 128;
    const API_KEY_PERMISSION_ONLY_READABLE_BY_OWNER = 0400;

    const SUPPORTED_USERS = [
        'dtccommander' => [
            'unix' => 'root',
            'roles' => [Roles::ROLE_INTERNAL_DTCCOMMANDER]
        ]
    ];

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var ShadowUser */
    private $shadowUsers;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param ShadowUser $shadowUsers
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        ShadowUser $shadowUsers
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->shadowUsers = $shadowUsers;
    }

    /**
     * Regenerate all api keys.
     */
    public function regenerateAll(): void
    {
        $this->logger->info("AKS0001 Regenerating all API keys ...");

        foreach (array_keys(self::SUPPORTED_USERS) as $user) {
            try {
                $this->regenerate($user);
            } catch (Throwable $e) {
                $this->logger->warning("AKS0004 Could not regenerate API key for user.", ['user' => $user, 'exception' => $e]);
            }
        }

        $this->logger->info("AKS0002 Finished regenerating all API keys");
    }

    /**
     * Regenerate an api key for a given user.
     *
     * @param string $user
     */
    public function regenerate(string $user): void
    {
        $this->logger->info("AKS0003 Regenerating API key for user...", ['user' => $user]);

        $this->validate($user);
        $this->save($user, PasswordGenerator::generate(self::API_KEY_LENGTH));
    }

    /**
     * Check to see if an api key matches for a given user.
     *
     * @param string $user
     * @param string $apiKey
     * @return ApiKey
     */
    public function get(string $user, string $apiKey)
    {
        if (!hash_equals($this->read($user), $apiKey)) {
            return null;
        }

        return new ApiKey(
            $user,
            $apiKey,
            self::SUPPORTED_USERS[$user]['roles']
        );
    }

    /**
     * @param string $user
     */
    private function validate(string $user): void
    {
        $valid = !empty($user)
            && isset(self::SUPPORTED_USERS[$user])
            && $this->shadowUsers->exists(self::SUPPORTED_USERS[$user]['unix'] ?? '', true);

        if (!$valid) {
            throw new InvalidArgumentException("User is not valid: $user");
        }
    }

    /**
     * @param string $user
     * @return string|null
     */
    private function read(string $user)
    {
        $apiKeyFile = sprintf(self::API_KEY_STORAGE_FORMAT, $user);

        if (!$this->filesystem->exists($apiKeyFile)) {
            return null;
        }

        return trim($this->filesystem->fileGetContents($apiKeyFile));
    }

    /**
     * @param string $user
     * @param string $apiKey
     */
    private function save(string $user, string $apiKey): void
    {
        $apiKeyFile = sprintf(self::API_KEY_STORAGE_FORMAT, $user);

        $unixUser = self::SUPPORTED_USERS[$user]['unix'];

        // Persist Api Key File
        $this->filesystem->mkdirIfNotExists(dirname($apiKeyFile), true, 0770);
        $this->filesystem->filePutContents($apiKeyFile, $apiKey);

        // Update Api Key File Permissions
        $this->filesystem->chmod($apiKeyFile, self::API_KEY_PERMISSION_ONLY_READABLE_BY_OWNER);
        $this->filesystem->chown($apiKeyFile, $unixUser);
        $this->filesystem->chgrp($apiKeyFile, $unixUser);
    }
}
