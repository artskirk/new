<?php

namespace Datto\Asset\Agent\Encryption;

/**
 * Exception for when an encrypted agent's master key is not decrypted and available. (The agent is "sealed")
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class SealedException extends \Exception
{

}
