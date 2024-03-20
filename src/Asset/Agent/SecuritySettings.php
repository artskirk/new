<?php

namespace Datto\Asset\Agent;

/**
 * Asset settings related to taking and storing snapshots on the device.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class SecuritySettings
{
    const DEFAULT_USER = '';

    /** @var string */
    private $user;

    /**
     * @param string $user authorized user for secure file restore and export
     */
    public function __construct(
        $user = self::DEFAULT_USER
    ) {
        $this->user =$user;
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param string
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @param SecuritySettings $settings
     */
    public function copyFrom(SecuritySettings $settings): void
    {
        $this->setUser($settings->getUser());
    }
}
