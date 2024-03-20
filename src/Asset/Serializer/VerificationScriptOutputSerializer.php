<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\VerificationScriptOutput;

/**
 * Class VerificationScriptOutputSerializer
 *
 * Unserializing:
 *  $verificationScriptOutput = $serializer->unserialize(array(
 *      VerificationScriptOutputSerializer::SCRIPT_NAME => 'name',
 *      VerificationScriptOutputSerializer::OUTPUT => 'output',
 *      VerificationScriptOutputSerializer::EXIT_CODE => 1
 *      VerificationScriptOutputSerializer::STATE => 1
 *  ));
 *
 * Serializing:
 *  $serializedVerificationScriptOutput = $serializer->serialize(new VerificationScriptOutput(...));
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationScriptOutputSerializer implements Serializer
{
    const STATE = 'state';
    const EXIT_CODE = 'exitCode';
    const OUTPUT = 'output';
    const SCRIPT_NAME = 'scriptName';

    /**
     * @param VerificationScriptOutput $verificationScriptOutput
     * @return string[]
     */
    public function serialize($verificationScriptOutput)
    {
        return array(
            static::SCRIPT_NAME => $verificationScriptOutput->getScriptName(),
            static::OUTPUT => $verificationScriptOutput->getOutput(),
            static::EXIT_CODE => $verificationScriptOutput->getExitCode(),
            static::STATE => $verificationScriptOutput->getState()
        );
    }

    /**
     * @param mixed $fileArray
     * @return VerificationScriptOutput
     */
    public function unserialize($fileArray)
    {
        $scriptName = isset($fileArray[static::SCRIPT_NAME]) ? $fileArray[static::SCRIPT_NAME] : null;
        $state = isset($fileArray[static::STATE]) ? $fileArray[static::STATE] : null;
        $output = isset($fileArray[static::OUTPUT]) ? $fileArray[static::OUTPUT] : null;
        $exitCode = isset($fileArray[static::EXIT_CODE]) ? $fileArray[static::EXIT_CODE] : null;

        return new VerificationScriptOutput($scriptName, $state, $output, $exitCode);
    }
}
