<?php

namespace Datto\Core\Drives;

use JsonSerializable;

/**
 * A basic health attribute, useful for reporting health information for drives that
 * don't support full SMART threshold-based attributes.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class DriveHealthAttribute implements JsonSerializable
{
    /** @var string The name of the attribute */
    private string $name;

    /** @var int The raw value of the attribute */
    private int $value;

    /** @var int The manufacturer-defined ID for this health attribute, or 0 for non-ATA health attributes */
    private int $id;

    /** @var int The manufacturer-defined health threshold for this attribute, or 0 for attributes without thresholds */
    private int $threshold;

    /** @var int|null The raw value for this, in the case of attributes where $value may be normalized */
    private ?int $raw;

    public function __construct(string $name, int $value, int $id = 0, int $threshold = 0, ?int $raw = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->id = $id;
        $this->threshold = $threshold;
        $this->raw = $raw;
    }

    /**
     * @return string The name of the health attribute
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The value of the attribute. Depending on the type of attribute, this may be normalized,
     * and not the raw value.
     *
     * @return int
     */
    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Gets the numeric ID of the attribute. These may vary by vendor and model, so any attempt to use
     * this value programmatically should take the drive model into account.
     *
     * @return int The attribute ID, or 0 for attributes without a manufacturer-defined ID
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Gets the threshold value against which the normalized "value" can be compared. For SMART attributes with
     * non-zero thresholds defined by the manufacturer, the drive is considered by the manufacturer to be good
     * as long as the normalized value is above the threshold. If the normalized value drops below the threshold,
     * then the SMART status is considered to be failed.
     *
     * @return int The threshold for the SMART attribute, or 0 if no threshold is defined.
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * Whether the manufacturer-reported value is indicative of a drive failure. This value should not be blindly
     * relied upon, as not all attributes have thresholds, and not all met or exceeded thresholds are indicative of
     * drive failures. Additional details will depend on the model of the individual drive.
     *
     * @return bool Whether the manufacturer-defined health threshold for this attribute has been met or exceeded
     */
    public function isFailing(): bool
    {
        return $this->getThreshold() !== 0 && $this->value <= $this->getThreshold();
    }

    /**
     * The raw value of this attribute. May be total gibberish based on the attribute. Use with care. For attributes
     * that do not specify a raw value, this will return the primary value field, which is assumed to be non-normalized.
     *
     * @return int|null The raw value of the health attribute, or null if no raw value is specified.
     */
    public function getRaw(): ?int
    {
        return $this->raw;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $json = [
            'name' => $this->getName(),
            'value' => $this->getValue()
        ];

        if ($this->getId()) {
            $json['id'] = $this->getId();
        }

        if ($this->getThreshold()) {
            $json['threshold'] = $this->getThreshold();
            $json['failing'] = $this->isFailing();
        }

        if ($this->getRaw() !== null) {
            $json['raw'] = $this->getRaw();
        }

        return $json;
    }
}
