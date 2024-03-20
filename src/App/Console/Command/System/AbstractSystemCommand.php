<?php

namespace Datto\App\Console\Command\System;

use Datto\System\PowerManager;
use Symfony\Component\Console\Command\Command;
use Datto\App\Console\Command\CommandValidator;

abstract class AbstractSystemCommand extends Command
{
    /** @var PowerManager */
    protected $powerManager;

    /** @var  CommandValidator */
    protected $validator;

    public function __construct(
        CommandValidator $validator,
        PowerManager $powerManager
    ) {
        parent::__construct();

        $this->validator = $validator;
        $this->powerManager = $powerManager;
    }
}
