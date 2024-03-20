<?php

namespace Datto\App\Console\Command\Restore\Iscsi;

use Datto\App\Console\Command\AbstractListCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Restore\Iscsi\IscsiMounterService;

abstract class AbstractIscsiCommand extends AbstractListCommand
{
    /** @var CommandValidator */
    protected $validator;

    /** @var IscsiMounterService */
    protected $iscsiMounter;

    public function __construct(
        CommandValidator $commandValidator,
        IscsiMounterService $iscsiMounterService
    ) {
        parent::__construct();

        $this->validator = $commandValidator;
        $this->iscsiMounter = $iscsiMounterService;
    }
}
