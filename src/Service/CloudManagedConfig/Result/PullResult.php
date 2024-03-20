<?php

namespace Datto\Service\CloudManagedConfig\Result;

use Throwable;

class PullResult
{
    private bool $changesToApply;
    private ?bool $successfullyApplied;
    private ?Throwable $exception;

    public function __construct(
        bool $changesToApply,
        ?bool $successfullyApplied = null,
        ?Throwable $exception = null
    ) {
        $this->changesToApply = $changesToApply;
        $this->successfullyApplied = $successfullyApplied;
        $this->exception = $exception;
    }

    public function hadChangesToApply(): bool
    {
        return $this->changesToApply;
    }

    public function wasSuccessfullyApplied(): ?bool
    {
        if (!$this->changesToApply) {
            return null;
        }

        return (bool) $this->successfullyApplied;
    }

    public function getErrorMessage(): ?string
    {
        return $this->exception ? $this->exception->getMessage() : null;
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    public function setResult(bool $successfullyApplied, ?Throwable $exception = null): void
    {
        $this->successfullyApplied = $successfullyApplied;
        $this->exception = $exception;
    }
}
