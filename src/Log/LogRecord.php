<?php

namespace Datto\Log;

/**
 * Represents a normalized log record
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogRecord
{
    /** @var array */
    private $record;

    public function __construct(array $record)
    {
        $this->record = $record;
    }

    public function getMessage(): string
    {
        return $this->record['message'] ?? '';
    }

    public function setMessage(string $message)
    {
        $this->record['message'] = $message;
    }

    public function getContext():  array
    {
        return $this->record['context'] ?? [];
    }

    public function setContext(array $context)
    {
        $this->record['context'] = $context;
    }

    public function addToContext(array $additionalContext)
    {
        $this->record['context'] = array_merge($this->record['context'], $additionalContext);
    }

    public function getLevel(): int
    {
        return $this->record['level'] ?? 0;
    }

    public function getChannel(): string
    {
        return $this->record['channel'] ?? '';
    }

    public function setChannel(string $channel)
    {
        $this->record['channel'] = $channel;
    }

    public function getDateTime()
    {
        return $this->record['datetime'];
    }

    public function getFormatted(): string
    {
        return $this->record['formatted'] ?? '';
    }

    public function getAlertCode(): string
    {
        return $this->record['extra']['alert_code'] ?? '';
    }

    public function setAlertCode(string $alertCode)
    {
        $this->record['extra']['alert_code'] = $alertCode;
    }

    public function getAlertSeverity(): int
    {
        return $this->record['extra']['alert_severity'] ?? '';
    }

    public function setAlertSeverity(int $alertSeverity)
    {
        $this->record['extra']['alert_severity'] = $alertSeverity;
    }

    public function getAlertCategory(): string
    {
        return $this->record['extra']['alert_category'] ?? '';
    }

    public function setAlertCategory(string $alertCategory)
    {
        $this->record['extra']['alert_category'] = $alertCategory;
    }

    public function getUser(): string
    {
        return $this->record['extra']['user'] ?? '';
    }

    public function setUser(string $user)
    {
        $this->record['extra']['user'] = $user;
    }

    public function hasCefExtensions(): bool
    {
        return isset($this->record['extra']['cef_extensions']);
    }

    public function getCefExtensions(): array
    {
        return $this->record['extra']['cef_extensions'] ?? [];
    }

    public function setCefExtensions(array $extensions)
    {
        $this->record['extra']['cef_extensions'] = $extensions;
    }

    public function getDeviceModel(): string
    {
        return $this->record['extra']['device_model'] ?? 'BackupDevice';
    }

    public function setDeviceModel(string $deviceModel)
    {
        $this->record['extra']['device_model'] = $deviceModel;
    }

    public function getPackageVersion(): string
    {
        return $this->record['extra']['os2_package_version'] ?? '';
    }

    public function setPackageVersion(string $packageVersion)
    {
        $this->record['extra']['os2_package_version'] = $packageVersion;
    }

    public function hasAsset(): bool
    {
        return isset($this->record['context']['asset']);
    }

    public function getAsset(): string
    {
        return $this->record['context']['asset'] ?? '';
    }

    public function hasSessionIdName(): bool
    {
        return isset($this->record['context']['session_id']);
    }

    public function getSessionIdName(): string
    {
        return $this->record['context']['session_id'] ?? '';
    }

    public function getContextId(): string
    {
        return $this->record['extra']['contextId'];
    }

    public function setContextId(string $contextId)
    {
        $this->record['extra']['contextId'] = $contextId;
    }

    public function shouldSendEvent(): bool
    {
        return !isset($this->record['context']['no_ship']);
    }

    public function toArray(): array
    {
        return $this->record;
    }
}
