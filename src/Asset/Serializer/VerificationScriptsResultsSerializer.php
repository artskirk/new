<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\VerificationScriptOutput;
use Datto\Asset\VerificationScriptsResults;

/**
 * Class VerificationScriptsResultsSerializer
 *
 * Unserializing:
 *  $verificationScriptsResults = $serializer->unserialize(array(
 *      VerificationScriptsResultsSerializer::COMPLETE => true|false
 *      VerificationScriptsResultsSerializer::OUTPUT => array($verificationScriptOutput)
 *  ));
 *
 * Serializing:
 *  $serializedVerificationScriptsResults = $serializer->serialize(new VerificationScriptsResults(...));
 *
 * @author Charles Shapleigh <cshapleigh@gmail.com>
 */
class VerificationScriptsResultsSerializer implements Serializer
{
    const COMPLETE = 'complete';
    const OUTPUT = 'output';

    /** @var VerificationScriptOutputSerializer */
    private $verificationScriptOutputSerializer;

    /**
     * @param VerificationScriptOutputSerializer $verificationScriptOutputSerializer
     */
    public function __construct(VerificationScriptOutputSerializer $verificationScriptOutputSerializer = null)
    {
        $this->verificationScriptOutputSerializer = $verificationScriptOutputSerializer ?: new VerificationScriptOutputSerializer();
    }

    /**
     * @param VerificationScriptsResults $verificationScriptsResults
     * @return array
     */
    public function serialize($verificationScriptsResults)
    {
        $output = array();
        foreach ($verificationScriptsResults->getOutput() as $out) {
            $output[] = $this->serializeVerificationScriptOutput($out);
        }

        return array(
            static::COMPLETE => $verificationScriptsResults->getComplete(),
            static::OUTPUT => $output
        );
    }

    /**
     * @param mixed $fileArray
     * @return VerificationScriptsResults
     */
    public function unserialize($fileArray)
    {
        $output = array();
        $complete = null;
        if (isset($fileArray['output'])) {
            foreach ($fileArray['output'] as $out) {
                $output[] = $this->unserializeVerificationScriptOutput($out);
            }
            $complete = isset($fileArray[static::COMPLETE]) ? $fileArray[static::COMPLETE] : null;
        }

        return new VerificationScriptsResults($complete, $output);
    }

    /**
     * @param $fileArray
     * @return VerificationScriptOutput
     */
    private function unserializeVerificationScriptOutput($fileArray)
    {
        return $this->verificationScriptOutputSerializer->unserialize($fileArray);
    }

    /**
     * @param $fileArray
     * @return string[]
     */
    private function serializeVerificationScriptOutput($fileArray)
    {
        return $this->verificationScriptOutputSerializer->serialize($fileArray);
    }
}
