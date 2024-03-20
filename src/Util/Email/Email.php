<?php

namespace Datto\Util\Email;

/**
 * Class to encapsulate email fields. This class is just plain model class.
 *
 * @author John Roland <jroland@datto.com>
 */
class Email
{
    /** @var string */
    private $recipients;

    /** @var string */
    private $subject;

    /** @var string|array */
    private $message;

    /** @var array */
    private $files;

    /** @var array */
    private $meta;

    /**
     * @param string $recipients
     * @param string $subject
     * @param string|array $message
     * @param array $files
     * @param array $meta
     */
    public function __construct($recipients, string $subject, $message, $files = null, $meta = null)
    {
        $this->recipients = $recipients;
        $this->subject = $subject;
        $this->message = $message;
        $this->files = $files;
        $this->meta = $meta;
    }

    /**
     * @return string|null
     */
    public function getRecipients()
    {
        return $this->recipients;
    }

    /**
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * @return string|array
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return array|null
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * @return array|null
     */
    public function getMeta()
    {
        return $this->meta;
    }
}
