<?php

namespace Datto\Config;

use Datto\Core\Configuration\ConfigRecordInterface;

/**
 * Represents the contents of the contactInfo file
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ContactInfoRecord implements ConfigRecordInterface
{
    const CONTACT_FIELD_FORMAT = "%s:%s";

    /** @var string */
    protected $email;

    /** @var string */
    protected $name;

    /** @var string */
    protected $company;

    /** @var string */
    protected $phone;

    /**
     * @param string $email
     */
    public function setEmail(string $email)
    {
        $this->email = $email;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * @param string $company
     */
    public function setCompany(string $company)
    {
        $this->company = $company;
    }

    /**
     * @param string $phone
     */
    public function setPhone(string $phone)
    {
        $this->phone = $phone;
    }

    /**
     * @return string|null
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * @return string|null
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function getCompany()
    {
        return $this->company;
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return 'contactInfo';
    }

    /**
     * @inheritdoc
     */
    public function unserialize(string $raw)
    {
        $raw = explode(PHP_EOL, trim($raw));

        foreach ($raw as $value) {
            list($var, $val) = explode(":", $value);
            switch ($var) {
                case 'name':
                    $this->name = $val;
                    break;
                case 'Company':
                    $this->company = base64_decode($val);
                    break;
                case 'phone':
                    $this->phone = base64_decode($val);
                    break;
                case 'email':
                    $this->email = base64_decode($val);
                    break;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function serialize(): string
    {
        $name = sprintf(self::CONTACT_FIELD_FORMAT, 'name', $this->name);
        $company = sprintf(self::CONTACT_FIELD_FORMAT, 'Company', base64_encode($this->company));
        $phone = sprintf(self::CONTACT_FIELD_FORMAT, 'phone', base64_encode($this->phone));
        $email = sprintf(self::CONTACT_FIELD_FORMAT, 'email', base64_encode($this->email));

        return $name . PHP_EOL . $company . PHP_EOL . $phone . PHP_EOL . $email;
    }
}
