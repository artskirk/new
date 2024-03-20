<?php

namespace Datto\App\Console\Command\Agent\Verification;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\App\Security\Constraints\AssetExists;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetType;
use Datto\Verification\VerificationService;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Snapctl command to dequeue a specific or all queued verifications
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class DequeueCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:verification:dequeue';

    /** @var VerificationService */
    private $verificationService;

    /** @var CommandValidator */
    private $commandValidator;

    public function __construct(
        CommandValidator $commandValidator,
        VerificationService $verificationService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->verificationService = $verificationService;
        $this->commandValidator = $commandValidator;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Dequeue verification for an agent.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'Name of the agent.')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot epoch as an integer. Will use the latest snapshot if left blank.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Dequeue All queued verifications');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $all = $input->getOption('all');
        if ($all) {
            $this->verificationService->removeAll();
        } else {
            $agentKey = $input->getArgument('agent');
            $snapshot = intval($input->getArgument('snapshot'));
            $agent = $this->agentService->get($agentKey);

            // use latest point if it wasn't set
            if ($snapshot === 0) {
                $snapshot = $agent->getLocal()->getRecoveryPoints()->getLast()->getEpoch();
            }

            if ($agent instanceof Agent && !$agent->isRescueAgent()) {
                $this->verificationService->remove($agent, $snapshot);
            } else {
                throw new Exception("Rescue agents do not support screenshots at this time.");
            }
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function validateArgs(InputInterface $input): void
    {
        $all = $input->getOption('all');
        if ($all === false) {
            $this->commandValidator->validateValue(
                $input->getArgument('agent'),
                new AssetExists(array('type' => AssetType::AGENT)),
                'Asset must exist'
            );
            $this->commandValidator->validateValue(
                $input->getArgument('snapshot'),
                new Assert\Regex(array('pattern' => '/^\d+$/')),
                'Snapshot epoch time must be an integer'
            );
        }
    }
}
