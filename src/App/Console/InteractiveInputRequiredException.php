<?php

namespace Datto\App\Console;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class InteractiveInputRequiredException extends CommandException
{
    public function __construct()
    {
        parent::__construct('This command requires interactive input.');
    }
}
