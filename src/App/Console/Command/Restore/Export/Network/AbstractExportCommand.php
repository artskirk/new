<?php

namespace Datto\App\Console\Command\Restore\Export\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Agent\AgentService;
use Datto\Restore\Export\Network\NetworkExportService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class AbstractExportCommand
 *
 * This is a base class for image export commands.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
abstract class AbstractExportCommand extends AbstractCommand
{
    /** @var  CommandValidator $validator Instance of CommandValidator. */
    private $validator;

    /** @var NetworkExportService */
    protected $exportService;

    /** @var AgentService */
    protected $agentService;

    public function __construct(
        CommandValidator $validator,
        NetworkExportService $exportService,
        AgentService $agentService
    ) {
        parent::__construct();

        $this->validator = $validator;
        $this->exportService = $exportService;
        $this->agentService = $agentService;
    }

    /**
     * Validates the input arguments.
     *
     * @param InputInterface $input Instance of InputInterface containing input arguments.
     */
    protected function validateArgs(InputInterface $input): void
    {
        $this->validator->validateValue(
            $input->getArgument('agent'),
            new Assert\NotNull(),
            'Agent must be specified'
        );
        $this->validator->validateValue(
            $input->getArgument('snapshot'),
            new Assert\NotNull(),
            'Snapshot must be specified'
        );
        $this->validator->validateValue(
            $input->getArgument('snapshot'),
            new Assert\Regex(array('pattern' => "~^[[:graph:]]+$~")),
            'Snapshot must be alphanumeric'
        );
    }
}
