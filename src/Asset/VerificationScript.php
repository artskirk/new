<?php

namespace Datto\Asset;

class VerificationScript
{
    /** @var string */
    private $name;

    /** @var string */
    private $id;

    /** @var string */
    private $tier;

    /** @var string */
    private $previousTier;

    /**
     * VerificationScript constructor.
     *
     * @param string $name
     * @param string $id
     * @param string $tier
     */
    public function __construct(string $name, string $id, string $tier)
    {
        $this->name = $name;
        $this->id = $id;
        $this->tier = $tier;
        $this->previousTier = $this->tier;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getTier(): string
    {
        return $this->tier;
    }

    /**
     * Gets the full unique id string
     *
     * @return string
     */
    public function getFullUniqueId(): string
    {
        return $this->tier . '_' . $this->id . '_' . $this->name;
    }

    /**
     * Get the filename of the script as we know it exists on the filesystem
     *
     * @return string
     */
    public function getFilename(): string
    {
        return $this->previousTier . '_' . $this->id . '_' . $this->name;
    }

    /**
     * @param string $tier
     */
    public function setTier(string $tier): void
    {
        $this->tier = $tier;
    }
}
