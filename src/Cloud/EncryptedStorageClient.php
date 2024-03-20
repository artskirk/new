<?php

namespace Datto\Cloud;

use Datto\System\Storage\Encrypted\EncryptedStorageService;
use Datto\Util\RetryAttemptsExhaustedException;
use Datto\Util\RetryHandler;

/**
 * Interface for storing and retreiving encryption key with cloud.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class EncryptedStorageClient
{
    const RETRY_WAIT_SECONDS = 30;
    const RETRY_ATTEMPTS = 6;

    /** @var JsonRpcClient */
    private $client;

    /** @var RetryHandler */
    private $retry;

    public function __construct(JsonRpcClient $client, RetryHandler $retry)
    {
        $this->client = $client;
        $this->retry = $retry;
    }

    /**
     * Get the storage encryption key.
     *
     * @return string
     */
    public function getKey(): string
    {
        $callable = function () {
            return $this->client->queryWithId('v1/device/storage/encryption/getKey');
        };

        try {
            $result = $this->retry->executeAllowRetry(
                $callable,
                self::RETRY_ATTEMPTS,
                self::RETRY_WAIT_SECONDS,
                true
            );
        } catch (RetryAttemptsExhaustedException $e) {
            throw $e->getPrevious() ?? $e;
        }

        if (strlen($result) !== EncryptedStorageService::GENERATED_KEY_STRING_LENGTH) {
            throw new \Exception('Cloud response did not contain a key with the correct length');
        }

        return $result;
    }
}
