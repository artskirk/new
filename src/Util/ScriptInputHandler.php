<?php

namespace Datto\Util;

use Datto\Common\Resource\ProcessFactory;

/**
 * Class ScriptInputHandler handles reading input from the command line.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ScriptInputHandler
{
    const TTY_PATH = '/bin/stty';
    const ECHO_ON  = 'echo';
    const ECHO_OFF = '-echo';

    private ProcessFactory $processFactory;

    /** @var IOStreams */
    private $ioStreams;

    public function __construct(
        ProcessFactory $processFactory = null,
        IOStreams $ioStreams = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->ioStreams = $ioStreams ?: new IOStreams();
    }

    /**
     * Read a line from stdin without echoing the user's keystrokes as they are entered
     *
     * @return string The line entered by the user
     */
    public function readHiddenInput()
    {
        $this->disableInputEcho();
        $line = $this->ioStreams->getStdin();
        $this->enableInputEcho();
        return $line;
    }

    /**
     * Turn on TTY Echo.
     */
    private function enableInputEcho()
    {
        $this->setEcho(ScriptInputHandler::ECHO_ON);
    }

    /**
     * Turn off TTY echo.
     */
    private function disableInputEcho()
    {
        $this->setEcho(ScriptInputHandler::ECHO_OFF);
    }

    /**
     * Set TTY echo with the provided argument
     *
     * @param $arg
     */
    private function setEcho($arg)
    {
        $process = $this->processFactory->get([ScriptInputHandler::TTY_PATH, $arg]);
        $process->setTty(true);
        $process->run();
    }
}
