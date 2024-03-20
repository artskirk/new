<?php

namespace Datto\Asset\Agent\Windows;

class VssWriterSetting
{
    private string $id;
    private bool $excluded;
    private ?string $name;

    public function __construct(string $id, bool $excluded, ?string $name)
    {
        $this->id = $id;
        $this->excluded = $excluded;
        $this->name = $name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }

    public function setExcluded(bool $excluded): void
    {
        $this->excluded = $excluded;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->id;
    }
}
