<?php

namespace Datto\Core\Storage;

/**
 * Context necessary for the expansion of a storage pool
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class StoragePoolExpansionContext extends StoragePoolCreationContext
{
    private string $raidLevel;
    private bool $requireRaid;

    public function __construct(
        string $name,
        array $drives,
        string $raidLevel = '',
        bool $requireRaid = false
    ) {
        parent::__construct($name, $drives);

        $this->raidLevel = $raidLevel;
        $this->requireRaid = $requireRaid;
    }

    public function getRaidLevel(): string
    {
        return $this->raidLevel;
    }

    public function isRaidRequired(): bool
    {
        return $this->requireRaid;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'raidLevel' => $this->getRaidLevel(),
                'isRaidRequired' => $this->isRaidRequired()
            ]
        );
    }
}
