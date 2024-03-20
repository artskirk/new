<?php

namespace Datto\Util\Email\Generator;

use Datto\Util\Email\Email;
use Datto\Util\Email\Mail_mimeDecode;
use Exception;

/**
 * Generates an Email from a MIME string.
 *
 * @author John Roland <jroland@datto.com>
 */
class MimeEmailGenerator
{
    /** @var Mail_mimeDecode */
    private $mimeDecoder;

    /**
     * @param Mail_mimeDecode|null $mimeDecoder
     */
    public function __construct(Mail_mimeDecode $mimeDecoder = null)
    {
        $this->mimeDecoder = $mimeDecoder ?: new Mail_mimeDecode();
    }

    /**
     * Parse the email message and create an instance of an Email object,
     *
     * @param string $emailData Typical usage of the mail() command will send the following to sendmail as stdin (between the == signs):
     * ======================================================
     * To: jroland@datto.com,j.roland@datto.com
     * Subject: this would be the subject
     * X-PHP-Originating-Script: 0:php shell code
     * From: jroland@datto.com
     * Reply-To: j.roland@datto.com
     *
     * this would be the message.
     * ======================================================
     * @return Email $email the Email object encapsulating the recipients, subject, and message.
     */
    public function generate(string $emailData): Email
    {
        $args = array();
        $args['include_bodies'] = true;
        $args['decode_bodies'] = true;

        $this->mimeDecoder->init($emailData);
        $email = $this->mimeDecoder->decode($args);
        $to = $email->headers['to'];
        $subject = $email->headers['subject'];
        $message = $this->getBody($email);

        return new Email($to, $subject, $message);
    }

    private function getBody($decodedEmail)
    {
        if ($decodedEmail->body !== null) {
            return $decodedEmail->body;
        } elseif ($decodedEmail->parts !== null) {
            switch (strtolower($decodedEmail->ctype_secondary)) {
                case 'mixed':
                case 'parallel':
                    return implode('\r\n', $decodedEmail->parts);

                case 'digest':
                case 'rfc822':
                    throw new Exception($decodedEmail->headers['content-type'] . ' is not supported.');

                default:
                    foreach ($decodedEmail->parts as $part) {
                        if ($part->ctype_secondary === 'html') {
                            return $part->body;
                        }
                    }
                    return $decodedEmail->parts[0]->body;
            }
        } else {
            throw new Exception('Failed to parse message body.');
        }
    }
}
