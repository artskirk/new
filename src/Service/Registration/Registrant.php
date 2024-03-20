<?php

namespace Datto\Service\Registration;

/**
 * Class Registrant holds the user's registration information.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Registrant
{
    /** @var string Username for the device login */
    private $user;

    /** @var string User's password */
    private $password;

    /** @var string Hostname of the device */
    private $hostname;

    /** @var string Timezone of the device */
    private $timezone;

    /** @var string Device alerts email */
    private $email;

    /** @var int Client organization ID this device is associated with */
    private $clientOrganizationId;

    /** @var string Client organization this device is associated with */
    private $clientOrganization;

    /** @var string The country where the offsite datacenter should be stored */
    private $datacenterLocation;

    /** @var string The recommended default datacenter for this customer */
    private $recommendedDatacenter;

    /**
     * Creates a registrant object to hold the user's registration information.
     *
     * @param string $user Username for the device login
     * @param string $password User's password
     * @param string $hostname Hostname of the device
     * @param string $timezone Timezone of the device
     * @param string $email Device alerts email
     * @param string $datacenterLocation Country where the offsite datacenter should be located
     * @param string $recommendedDatacenter
     */
    public function __construct(
        string $user,
        string $password,
        string $hostname,
        string $timezone,
        string $email,
        string $datacenterLocation = '',
        string $recommendedDatacenter = ''
    ) {
        $this->user = $user;
        $this->password = $password;
        $this->hostname = $hostname;
        $this->timezone = $timezone;
        $this->email = $email;
        $this->datacenterLocation = $datacenterLocation;
        $this->recommendedDatacenter = $recommendedDatacenter;
    }

    /**
     * @return string Username for the device login
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * @return string User's password
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return string Hostname of the device
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return string Timezone of the device
     */
    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * @return string Device alerts email
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return string The country where the offsite datacenter should be located
     */
    public function getDatacenterLocation(): string
    {
        return $this->datacenterLocation;
    }

    /**
     * @return string The recommended default datacenter location.
     */
    public function getRecommendedDatacenter(): string
    {
        return $this->recommendedDatacenter;
    }
}
