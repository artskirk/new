<?php

namespace Datto\Core\Drives;

use JsonSerializable;

/**
 * An element that indicates an error on a given drive. While this is similar to a DriveHealthAttribute, those are
 * intended to accurately reflect the information pulled directly from the drive. These errors, on the other hand,
 * can be added later after doing more analysis, and will generally be displayed on the user interface.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class DriveError implements JsonSerializable
{
    public const TYPE_SELFTEST = 'selftest';
    public const TYPE_ATTRIBUTE = 'attribute';

    public const LEVEL_WARN = 'warning';
    public const LEVEL_ERROR = 'error';

    private string $type;
    private string $level;
    private ?string $extra;

    public function __construct(string $type, string $level, ?string $extra = null)
    {
        $this->type = $type;
        $this->level = $level;
        $this->extra = $extra;
    }

    /**
     * @return string The type of the error
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string The severity level (warning, error) of the error
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * @return string|null Optional extra context for the error (for example, an Attribute name)
     */
    public function getExtra(): ?string
    {
        return $this->extra;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $json = [
            'type' => $this->getType(),
            'level' => $this->getLevel(),
        ];

        if ($this->getExtra()) {
            $json['extra'] = $this->getExtra();
        }

        return $json;
    }
}
