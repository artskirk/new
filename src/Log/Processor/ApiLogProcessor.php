<?php
namespace Datto\Log\Processor;

use Datto\Log\LogRecord;
use Datto\Util\ArraySanitizer;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Log processor for use with JSON-RPC API logger.
 *
 * Processes log record to format the log message, sanitize sensitive info, and
 * inject cloud logging info as needed.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class ApiLogProcessor
{
    const JSON_MESSAGE_TOKEN = 'jsonMessage';

    /** @var TokenStorageInterface */
    private $tokenStorage;

    private ArraySanitizer $arraySanitizer;

    public function __construct(TokenStorageInterface $tokenStorage, ArraySanitizer $arraySanitizer)
    {
        $this->tokenStorage = $tokenStorage;
        $this->arraySanitizer = $arraySanitizer;
    }

    /**
     * Processes log record to make it suitable for json-rpc API log
     *
     * @param array $record
     *
     * @return array
     */
    public function __invoke(array $record): array
    {
        $logRecord = new LogRecord($record);
        $context = $logRecord->getContext();

        // logs coming from API must have this set
        if (!isset($context[self::JSON_MESSAGE_TOKEN])) {
            return $record;
        }

        $rawMessage = $context[self::JSON_MESSAGE_TOKEN];
        unset($context[self::JSON_MESSAGE_TOKEN]);
        $jsonMessage = json_decode($rawMessage, true);

        // invalid json message
        if ($jsonMessage === null) {
            return $record;
        }

        $token = $this->tokenStorage->getToken();
        $context['user'] = $token ? $token->getUserIdentifier() : '';

        // fix message
        $context[self::JSON_MESSAGE_TOKEN] = $this->arraySanitizer->sanitizeParams($jsonMessage);

        // set new values
        $logRecord->setContext($context);

        return $logRecord->toArray();
    }
}
