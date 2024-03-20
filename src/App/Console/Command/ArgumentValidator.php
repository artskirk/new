<?php

namespace Datto\App\Console\Command;

use Symfony\Component\Console\Input\InputInterface;

interface ArgumentValidator
{
    /**
     * This must be implemented and call the validateValue method below to check arguments
     * @param InputInterface $input
     * @return void
     */
    public function validateArgs(InputInterface $input);
}
