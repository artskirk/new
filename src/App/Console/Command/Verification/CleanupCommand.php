<?php

namespace Datto\App\Console\Command\Verification;

use Datto\Verification\VerificationCleanupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that cleans up verification remnants.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CleanupCommand extends Command
{
    protected static $defaultName = 'verification:cleanup';

    /** @var VerificationCleanupManager  */
    private $cleanupManager;

    public function __construct(
        VerificationCleanupManager $cleanupManager
    ) {
        parent::__construct();

        $this->cleanupManager = $cleanupManager;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Cleans up verification remnants. Optionally, specify an agent whose verification remnants you want cleaned up.')
            ->addOption('agent', 'a', InputOption::VALUE_OPTIONAL, 'Name of the agent to cleanup.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getOption('agent');
        $agent = $agent === null ? '' : trim($agent);
        $this->cleanupManager->cleanupVerifications($agent);
        return 0;
    }
}
